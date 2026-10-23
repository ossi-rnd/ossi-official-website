<?php

use App\Actions\Attendance\RecordPunch;
use App\Contracts\ReverseGeocoder;
use App\Enums\PunchType;
use App\Enums\Role;
use App\Enums\TimeLogStatus;
use App\Livewire\Attendance\ClockPanel;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\TimeLogPunch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fakes\FakeReverseGeocoder;

function punch(PunchType $type, ?User $user = null, ?string $address = 'Ortigas Center'): TimeLog
{
    return app(RecordPunch::class)->handle($user ?? test()->employee, $type, cameraPhoto(), 14.5869, 121.0614, 8, $address);
}

beforeEach(function () {
    $this->employee = employee();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:05')); // Default shift: 09:00–18:00, 10 min grace.
});

it('records a clock-in with the photo stored privately', function () {
    $log = punch(PunchType::ClockIn);

    $punch = $log->punches()->sole();

    expect($log->status)->toBe(TimeLogStatus::Incomplete)
        ->and($log->late_minutes)->toBe(0)
        ->and($punch->address)->toBe('Ortigas Center')
        ->and($punch->latitude)->toBe('14.5869000')
        ->and(str_starts_with($punch->photo_path, "punches/{$this->employee->id}/2026-10-08/clock_in-"))->toBeTrue();

    Storage::disk(config('attendance.photo_disk'))->assertExists($punch->photo_path);
});

it('marks shift start + grace + 5 minutes as 5 minutes late', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:15'));

    $log = punch(PunchType::ClockIn);

    expect($log->late_minutes)->toBe(5)->and($log->status)->toBe(TimeLogStatus::Late);
});

it('rejects a second clock-in, so two rapid taps make one punch', function () {
    punch(PunchType::ClockIn);

    expect(fn () => punch(PunchType::ClockIn))->toThrow(ValidationException::class, 'You have already clocked in today.');

    expect(TimeLogPunch::query()->count())->toBe(1)
        ->and(TimeLog::query()->count())->toBe(1);
    // The rejected punch's photo was cleaned up too.
    expect(Storage::disk(config('attendance.photo_disk'))->allFiles())->toHaveCount(1);
});

it('rejects a clock-out before any clock-in, and a second clock-out', function () {
    expect(fn () => punch(PunchType::ClockOut))->toThrow(ValidationException::class, 'You must clock in before you can clock out.');

    punch(PunchType::ClockIn);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00'));
    punch(PunchType::ClockOut);

    expect(fn () => punch(PunchType::ClockOut))->toThrow(ValidationException::class, 'You have already clocked out today.');
});

it('computes undertime, overtime and the final status at clock-out', function (string $out, int $undertime, int $overtime, TimeLogStatus $status) {
    punch(PunchType::ClockIn);
    $this->travelTo(CarbonImmutable::parse("2026-10-08 {$out}"));

    $log = punch(PunchType::ClockOut);

    expect($log->undertime_minutes)->toBe($undertime)
        ->and($log->overtime_minutes)->toBe($overtime)
        ->and($log->status)->toBe($status);
})->with([
    'left early' => ['17:00', 60, 0, TimeLogStatus::Undertime],
    'on time' => ['18:00', 0, 0, TimeLogStatus::OnTime],
    'stayed two hours' => ['20:05', 0, 120, TimeLogStatus::OnTime],
]);

it('closes last night\'s log when a night-shift guard clocks out the next morning', function () {
    $night = Shift::factory()->night()->create();
    $this->employee->employeeProfile?->shift()->associate($night)->save();

    $this->travelTo(CarbonImmutable::parse('2026-10-08 21:58'));
    punch(PunchType::ClockIn);

    $this->travelTo(CarbonImmutable::parse('2026-10-09 06:02'));
    $log = punch(PunchType::ClockOut);

    expect($log->log_date->toDateString())->toBe('2026-10-08')
        ->and($log->undertime_minutes)->toBe(0)
        ->and($log->status)->toBe(TimeLogStatus::OnTime)
        ->and(TimeLog::query()->count())->toBe(1);
});

it('refuses a punch without a real photo or valid GPS', function () {
    expect(fn () => app(RecordPunch::class)->handle($this->employee, PunchType::ClockIn, 'data:image/jpeg;base64,'.base64_encode('not an image'), 14.5, 121.0))
        ->toThrow(ValidationException::class, 'The photo is not a valid image.');

    expect(fn () => app(RecordPunch::class)->handle($this->employee, PunchType::ClockIn, cameraPhoto(), 95.0, 121.0))
        ->toThrow(ValidationException::class);

    expect(TimeLog::query()->count())->toBe(0);
});

it('saves the punch with a blank address when the lookup failed', function () {
    $log = punch(PunchType::ClockIn, address: null);

    expect($log->punches()->sole()->address)->toBeNull();
});

it('clocks in through the Livewire clock panel and reports the result', function () {
    Livewire::actingAs($this->employee)
        ->test(ClockPanel::class)
        ->call('submitPunch', 'clock_in', cameraPhoto(), 14.5869, 121.0614, 8, 'Ortigas')
        ->assertReturned(['ok' => true, 'message' => 'Clocked in. Have a safe shift.'])
        ->assertDispatched('punch-recorded')
        ->call('submitPunch', 'clock_in', cameraPhoto(), 14.5869, 121.0614, 8, 'Ortigas')
        ->assertReturned(['ok' => false, 'message' => 'You have already clocked in today.']);
});

describe('punch photos and geocoding', function () {
    it('serves a punch photo only to its owner and attendance supervisors', function () {
        $punch = punch(PunchType::ClockIn)->punches()->sole();

        $this->actingAs($this->employee)->get(route('punches.photo', $punch))->assertOk();
        $this->actingAs(staff(Role::Hr))->get(route('punches.photo', $punch))->assertOk();
        $this->actingAs(employee())->get(route('punches.photo', $punch))->assertForbidden();
        // Accounting checks the log behind payslips (2026-10-23), so it sees the photos too; a Developer doesn't.
        $this->actingAs(staff(Role::Accounting))->get(route('punches.photo', $punch))->assertOk();
        $this->actingAs(staff(Role::Developer))->get(route('punches.photo', $punch))->assertForbidden();
    });

    it('looks up an address, and reports a failed lookup without breaking', function () {
        $this->actingAs($this->employee)->getJson(route('attendance.reverse-geocode', ['lat' => 14.58, 'lon' => 121.06]))
            ->assertOk()->assertJson(['address' => 'Ortigas Center, Pasig City']);

        $fake = new FakeReverseGeocoder;
        $fake->fails = true;
        $this->app->instance(ReverseGeocoder::class, $fake);

        $this->actingAs($this->employee)->getJson(route('attendance.reverse-geocode', ['lat' => 14.58, 'lon' => 121.06]))->assertStatus(502);
    });

    it('rate limits address lookups to 20 per user per minute', function () {
        foreach (range(1, 20) as $attempt) {
            $this->actingAs($this->employee)->getJson(route('attendance.reverse-geocode', ['lat' => 14.58, 'lon' => 121.06]))->assertOk();
        }

        $this->actingAs($this->employee)->getJson(route('attendance.reverse-geocode', ['lat' => 14.58, 'lon' => 121.06]))->assertTooManyRequests();
    });
});
