<?php

use App\Actions\Attendance\RecordPunch;
use App\Actions\Patrols\RecordPatrolScan;
use App\Actions\Patrols\SendPatrolAlerts;
use App\Enums\PunchType;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\Patrols;
use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\PatrolCheckpoint;
use App\Models\PatrolScan;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use App\Notifications\PatrolAlert;
use App\Queries\Attendance\PatrolRoundsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Stopping a roving guard from scanning photos of the stickers instead of walking the route.

const PATROL_LAT = 14.3260;
const PATROL_LNG = 120.9370;

function pinnedCheckpoint(Client $site, string $name, float $lat, float $lng): PatrolCheckpoint
{
    $checkpoint = new PatrolCheckpoint;
    $checkpoint->forceFill(['client_id' => $site->id, 'name' => $name, 'code' => Str::random(32), 'latitude' => $lat, 'longitude' => $lng])->save();

    return $checkpoint;
}

function scanAt(User $guard, PatrolCheckpoint $checkpoint, string $at, ?float $lat = null, ?float $lng = null): PatrolScan
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(RecordPatrolScan::class)->handle($guard, $checkpoint->qrPayload(), cameraPhoto(), $lat ?? (float) $checkpoint->latitude, $lng ?? (float) $checkpoint->longitude, 8);
}

function roundTwo(User $guard): array
{
    $log = TimeLog::query()->where('user_id', $guard->id)->firstOrFail();

    return app(PatrolRoundsQuery::class)->handle($log, CarbonImmutable::now())['rounds'][1];
}

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-12 06:00'));
    $shift = Shift::query()->create(['name' => 'Day Duty', 'start_time' => '06:00', 'end_time' => '15:00', 'grace_minutes' => 10]);
    $this->site = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Dasmariñas', 'latitude' => PATROL_LAT, 'longitude' => PATROL_LNG, 'geofence_meters' => 150, 'patrol_interval_minutes' => 120]);

    // Three spots ~33 m from the middle, ~45–65 m from each other.
    $this->gate = pinnedCheckpoint($this->site, 'Main gate', PATROL_LAT + 0.0003, PATROL_LNG);
    $this->parking = pinnedCheckpoint($this->site, 'Parking B2', PATROL_LAT - 0.0003, PATROL_LNG);
    $this->dock = pinnedCheckpoint($this->site, 'Loading dock', PATROL_LAT, PATROL_LNG + 0.0003);

    $this->rover = employee(['client_id' => $this->site->id, 'detachment' => $this->site->name, 'post' => 'Roving']);
    $duty = new DutyAssignment;
    $duty->forceFill(['user_id' => $this->rover->id, 'duty_date' => '2026-10-12', 'shift_id' => $shift->id, 'client_id' => $this->site->id, 'post' => 'Roving', 'patrols' => true])->save();
    app(RecordPunch::class)->handle($this->rover, PunchType::ClockIn, cameraPhoto(), PATROL_LAT, PATROL_LNG, 10);

    $this->opsHq = staff(Role::OperationsHq);
});

it('accepts a walked round', function () {
    scanAt($this->rover, $this->gate, '2026-10-12 08:10');
    scanAt($this->rover, $this->parking, '2026-10-12 08:13');
    scanAt($this->rover, $this->dock, '2026-10-12 08:16');

    expect(roundTwo($this->rover))->state->toBe('done')->suspicious->toBeNull();
});

it('flags a round scanned faster than anyone walks between checkpoints, and alerts Operations once', function () {
    Notification::fake();
    scanAt($this->rover, $this->gate, '2026-10-12 08:10:00');
    scanAt($this->rover, $this->parking, '2026-10-12 08:10:06');
    scanAt($this->rover, $this->dock, '2026-10-12 08:10:12');

    expect(roundTwo($this->rover)['suspicious'])->toBe('3 checkpoints scanned in 12 seconds');

    app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
    app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
    $faked = Notification::sent($this->opsHq, PatrolAlert::class)->filter(fn (PatrolAlert $alert) => str_contains($alert->headline, 'looks faked'));
    expect($faked)->toHaveCount(1)
        ->and($faked->first()?->headline)->toBe('Round 2 looks faked at Metrobank – Dasmariñas');
});

it('flags a round scanned from one spot though the checkpoints are metres apart', function () {
    // From the guardhouse in the middle: each sticker's photo is "within reach" of its pin, but they never moved.
    scanAt($this->rover, $this->gate, '2026-10-12 08:10', PATROL_LAT, PATROL_LNG);
    scanAt($this->rover, $this->parking, '2026-10-12 08:14', PATROL_LAT, PATROL_LNG);
    scanAt($this->rover, $this->dock, '2026-10-12 08:18', PATROL_LAT, PATROL_LNG);

    expect(roundTwo($this->rover)['suspicious'])->toBe('all 3 checkpoints scanned from one spot');
});

it('refuses a pinned checkpoint scanned from beyond its 50 m', function () {
    expect(fn () => scanAt($this->rover, $this->gate, '2026-10-12 08:10', PATROL_LAT - 0.0006, PATROL_LNG))
        ->toThrow(ValidationException::class, 'from Main gate');
});

it('makes the old sticker, and any photo of it, stop working after "New sticker"', function () {
    $oldPayload = $this->gate->qrPayload();

    Livewire::actingAs($this->opsHq)->test(Patrols::class)
        ->set('tab', 'checkpoints')->set('clientId', (string) $this->site->id)
        ->assertSee('Pinned')
        ->call('renewCheckpoint', $this->gate->id)->assertHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:10'));
    expect(fn () => app(RecordPatrolScan::class)->handle($this->rover, $oldPayload, cameraPhoto(), (float) $this->gate->latitude, (float) $this->gate->longitude, 8))
        ->toThrow(ValidationException::class, 'isn\'t an OSSI checkpoint')
        ->and(app(RecordPatrolScan::class)->handle($this->rover, $this->gate->fresh()?->qrPayload() ?? '', cameraPhoto(), (float) $this->gate->latitude, (float) $this->gate->longitude, 8)->checkpoint->name)->toBe('Main gate');
});

it('shows Operations each scan\'s photo, and keeps it from others', function () {
    $scan = scanAt($this->rover, $this->gate, '2026-10-12 08:10');

    Livewire::actingAs($this->opsHq)->test(Patrols::class)
        ->call('viewScans', $scan->time_log_id)
        ->assertSee('Main gate')
        ->assertSee(route('patrol-scans.photo', $scan), false);

    $this->actingAs($this->opsHq)->get(route('patrol-scans.photo', $scan))->assertOk();
    $this->actingAs($this->rover)->get(route('patrol-scans.photo', $scan))->assertOk(); // their own

    $cebu = staff(Role::SatelliteOperations);
    $cebu->forceFill(['operations_region' => Region::CentralVisayas])->save();
    $this->actingAs($cebu)->get(route('patrol-scans.photo', $scan))->assertForbidden();
    $this->actingAs(employee())->get(route('patrol-scans.photo', $scan))->assertForbidden();
});
