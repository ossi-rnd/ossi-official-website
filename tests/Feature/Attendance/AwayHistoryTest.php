<?php

use App\Actions\Attendance\RecordPunch;
use App\Actions\Patrols\CloseForgottenAways;
use App\Actions\Patrols\LeavePost;
use App\Actions\Patrols\ReturnToPost;
use App\Actions\Patrols\UpdateAwayLocation;
use App\Enums\AwayReason;
use App\Enums\PunchType;
use App\Enums\Role;
use App\Livewire\Admin\AwayMonitor;
use App\Livewire\Admin\EmployeeDetail;
use App\Models\AwayLocationUpdate;
use App\Models\AwayLog;
use App\Notifications\PatrolAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// Keeping time away on record: history by day, closing what nobody ended,
// and deleting the GPS trail after the retention period.

const HQ_LAT = 14.5869;
const HQ_LNG = 121.0614;

beforeEach(function () {
    config(['attendance.office' => ['name' => 'OSSI Head Office', 'latitude' => HQ_LAT, 'longitude' => HQ_LNG, 'radius_meters' => 150]]);
    $this->accountant = staff(Role::Accounting);
    $this->accountant->forceFill(['name' => 'Patricia Accountant'])->save();
    $this->executive = staff(Role::President);
});

/** Clock in on that day, step out for official business, and (unless $back is null) come back. */
function awayOn(string $left, ?string $back, ?object $test = null): AwayLog
{
    $test ??= test();
    $test->travelTo(CarbonImmutable::parse($left)->setTime(9, 0));
    app(RecordPunch::class)->handle($test->accountant, PunchType::ClockIn, cameraPhoto(), HQ_LAT, HQ_LNG, 10);
    $test->travelTo(CarbonImmutable::parse($left));
    $away = app(LeavePost::class)->handle($test->accountant, AwayReason::OfficialBusiness, 'BIR Pasig, filing 1601-C', HQ_LAT, HQ_LNG);
    $test->travelTo(CarbonImmutable::parse($left)->addMinutes(20));
    app(UpdateAwayLocation::class)->handle($test->accountant, 14.5880, 121.0570, 10);

    if ($back !== null) {
        $test->travelTo(CarbonImmutable::parse($back));
        app(ReturnToPost::class)->handle($test->accountant, HQ_LAT, HQ_LNG, 10);
    }

    return $away->fresh() ?? $away;
}

it('closes time away nobody ended, an hour after the duty\'s end, and flags it', function () {
    $away = awayOn('2026-10-12 15:00', null, $this);
    $dutyEnd = CloseForgottenAways::dutyEnd($away);

    // Within the hour after the duty: left alone (they may still tap "Back" or clock out).
    $this->travelTo($dutyEnd->addMinutes(59));
    expect(app(CloseForgottenAways::class)->handle(CarbonImmutable::now()))->toBe(0);

    $this->travelTo($dutyEnd->addMinutes(60));
    $this->artisan('app:close-forgotten-aways')->expectsOutputToContain('Closed 1 forgotten absence')->assertSuccessful();

    $away->refresh();
    expect($away->returned_at?->equalTo($dutyEnd))->toBeTrue()
        ->and($away->auto_closed_at)->not->toBeNull()
        ->and($away->outcome(CarbonImmutable::now()))->toBe(['red', 'Never ended: closed at duty end'])
        ->and(app(CloseForgottenAways::class)->handle(CarbonImmutable::now()))->toBe(0); // once
});

it('alerts the supervisor when time away was never ended', function () {
    Notification::fake();
    $away = awayOn('2026-10-12 15:00', null, $this);
    $this->travelTo(CloseForgottenAways::dutyEnd($away)->addMinutes(60));

    app(CloseForgottenAways::class)->handle(CarbonImmutable::now());

    // Office staff: their supervisor is the Executive (as for their other time-away alerts).
    Notification::assertSentTo($this->executive, PatrolAlert::class, fn (PatrolAlert $alert) => $alert->headline === 'Time away never ended (Official business)'
        && str_contains($alert->detail, 'never tapped "Back" or clocked out')
        && $alert->url === route('office-away'));
    Notification::assertNotSentTo($this->accountant, PatrolAlert::class);
});

it('closes an absence left open from an earlier duty at the next clock-in, even without the scheduler', function () {
    $away = awayOn('2026-10-12 15:00', null, $this);

    // Next morning: clocking in ends yesterday's absence (at yesterday's duty end), flagged.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 08:55'));
    app(RecordPunch::class)->handle($this->accountant, PunchType::ClockIn, cameraPhoto(), HQ_LAT, HQ_LNG, 10);

    $away->refresh();
    expect($away->returned_at?->equalTo(CloseForgottenAways::dutyEnd($away)))->toBeTrue()
        ->and($away->auto_closed_at)->not->toBeNull()
        ->and(AwayLog::query()->open()->count())->toBe(0);
});

it('keeps earlier days on record and lets the Executive look them up by day', function () {
    awayOn('2026-10-10 10:00', '2026-10-10 11:30', $this);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00'));

    Livewire::actingAs($this->executive)->test(AwayMonitor::class)
        ->assertSee('Nobody has come back yet today.')
        ->assertDontSee('Patricia Accountant')
        ->set('day', '2026-10-10')
        ->assertSee('Time away on Sat, Oct 10, 2026 · 1')
        ->assertSee('Patricia Accountant')
        ->call('nextDay')->assertSet('day', '2026-10-11')->assertSee('No time away on that day.')
        ->call('nextDay')->assertSet('day', '') // today
        ->set('day', '2030-01-01')->assertSee('Back in the office today'); // the future isn't a day to look at
});

it('deletes the GPS trail after the retention period but keeps the summary', function () {
    $old = awayOn('2026-06-01 10:00', '2026-06-01 11:00', $this);
    $recent = awayOn('2026-10-10 10:00', '2026-10-10 11:00', $this);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:30'));

    $this->artisan('app:prune-away-locations')->expectsOutputToContain('Deleted the location trail of 1 absence')->assertSuccessful();

    $old->refresh();
    expect(AwayLocationUpdate::query()->where('away_log_id', $old->id)->exists())->toBeFalse()
        ->and($old->left_latitude)->toBeNull()
        ->and($old->returned_latitude)->toBeNull()
        ->and($old->locations_pruned_at)->not->toBeNull()
        // The record stays.
        ->and($old->reason)->toBe(AwayReason::OfficialBusiness)
        ->and($old->note)->toBe('BIR Pasig, filing 1601-C')
        ->and($old->minutesAway(CarbonImmutable::now()))->toBe(60)
        // A recent one keeps its trail; a second run deletes nothing more.
        ->and(AwayLocationUpdate::query()->where('away_log_id', $recent->id)->exists())->toBeTrue();
    $this->artisan('app:prune-away-locations')->expectsOutputToContain('of 0 absences');

    Livewire::actingAs($this->executive)->test(AwayMonitor::class)
        ->set('day', '2026-06-01')
        ->call('viewAway', $old->id)
        ->assertSee('Location trail deleted')
        ->assertSee('BIR Pasig, filing 1601-C');
});

it('shows the last 30 days of time away on the employee record', function () {
    awayOn('2026-10-10 10:00', '2026-10-10 13:00', $this); // official business: back by 12:00, so an hour late
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00'));

    Livewire::actingAs($this->executive)->test(EmployeeDetail::class, ['profile' => $this->accountant->employeeProfile()->firstOrFail()])
        ->assertSee('Time away · last 30 days')
        ->assertSee('Sat, Oct 10 · Official business')
        ->assertSee('Back 60 min after 12:00 PM')
        ->assertSee('back after their time 1×');
});
