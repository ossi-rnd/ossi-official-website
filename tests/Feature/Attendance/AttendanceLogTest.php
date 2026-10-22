<?php

use App\Enums\PunchType;
use App\Enums\Role;
use App\Enums\TimeLogStatus;
use App\Livewire\Admin\AttendanceLog;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// The Attendance Log shows one row per shift (clock-in and clock-out side by side)
// under a heading per day, with views for who's on duty, late, or never clocked out.

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 14:00'));
    $this->hr = staff(Role::Hr);
    $this->day = Shift::query()->create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 10, 'is_default' => true]);
    $this->morning = Shift::query()->create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '12:00', 'grace_minutes' => 10]);
    $this->night = Shift::query()->create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '07:00', 'grace_minutes' => 10]);

    $this->named = function (string $name): User {
        $user = employee(['name' => $name]);
        $user->forceFill(['name' => $name])->save();

        return $user;
    };
    $this->punch = fn (TimeLog $log, PunchType $type, CarbonImmutable $at) => $log->punches()->forceCreate([
        'type' => $type, 'punched_at' => $at, 'photo_path' => 'punches/x.jpg', 'latitude' => 14.66708, 'longitude' => 121.01616, 'address' => 'Project 8, Quezon City',
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('puts a whole shift on one row, under its day', function () {
    $yesterday = CarbonImmutable::parse('2026-10-20');
    $log = TimeLog::factory()->for(($this->named)('Juan Complete'))->on($yesterday)->create(['shift_id' => $this->day->id]);
    ($this->punch)($log, PunchType::ClockIn, $yesterday->setTime(9, 0));
    ($this->punch)($log, PunchType::ClockOut, $yesterday->setTime(18, 0));

    $page = Livewire::actingAs($this->hr)->test(AttendanceLog::class)
        ->assertSeeInOrder(['Yesterday · Tue, Oct 20', 'Juan Complete', '9:00 AM', '6:00 PM', '9 hr', 'On Time'])
        ->assertSee('Clock in selfie of Juan Complete')->assertSee('Clock out selfie of Juan Complete')
        // The position opens in Google Maps (satellite, 3D, Street View; the Maps app on a phone).
        ->assertSee('https://www.google.com/maps/search/?api=1&query=14.66708,121.01616');

    expect($page->instance()->logs()->total())->toBe(1); // one row, not one per punch
});

it('tells on duty from not clocked out by the shift\'s end, night shifts included', function () {
    $today = CarbonImmutable::parse('2026-10-21');
    $yesterday = $today->subDay();

    // Clocked in at 9, Day shift ends 18:00, it's 14:00: on duty.
    $working = TimeLog::factory()->for(($this->named)('Ana Working'))->create(['shift_id' => $this->day->id, 'log_date' => $today, 'clock_in_at' => $today->setTime(9, 0), 'clock_out_at' => null, 'status' => TimeLogStatus::Incomplete]);
    // Morning shift ended 12:00: not clocked out.
    TimeLog::factory()->for(($this->named)('Ben Forgot'))->create(['shift_id' => $this->morning->id, 'log_date' => $today, 'clock_in_at' => $today->setTime(6, 0), 'clock_out_at' => null, 'status' => TimeLogStatus::Incomplete]);
    // Last night's 22:00–07:00 ended this morning: not clocked out.
    $cora = TimeLog::factory()->for(($this->named)('Cora Night'))->create(['shift_id' => $this->night->id, 'log_date' => $yesterday, 'clock_in_at' => $yesterday->setTime(22, 0), 'clock_out_at' => null, 'status' => TimeLogStatus::Incomplete]);
    ($this->punch)($working, PunchType::ClockIn, $today->setTime(9, 0));

    $page = Livewire::actingAs($this->hr)->test(AttendanceLog::class)
        ->assertSee('On duty')->assertSee('Not clocked out')->assertSee('Shift ended 12:00 PM');
    expect(count($page->instance()->open()['onDuty']))->toBe(1)
        ->and(count($page->instance()->open()['notClockedOut']))->toBe(2);

    $page->call('showOnly', AttendanceLog::ON_DUTY)
        ->assertSee('Ana Working')->assertDontSee('Ben Forgot')->assertDontSee('Cora Night');
    $page->call('showOnly', AttendanceLog::NOT_CLOCKED_OUT)
        ->assertSee('Ben Forgot')->assertSee('Cora Night')->assertDontSee('Ana Working');

    // A night shift still running after midnight is on duty, not "not clocked out".
    CarbonImmutable::setTestNow($today->setTime(2, 0));
    $fresh = Livewire::actingAs($this->hr)->test(AttendanceLog::class);
    expect($fresh->instance()->open()['onDuty'])->toContain($cora->id) // her night shift ends at 07:00
        ->and($fresh->instance()->open()['notClockedOut'])->not->toContain($cora->id);
});

it('lists late shifts, with the lateness and leaving early in words', function () {
    $yesterday = CarbonImmutable::parse('2026-10-20');
    TimeLog::factory()->for(($this->named)('Late Larry'))->on($yesterday)->create(['shift_id' => $this->day->id, 'clock_in_at' => $yesterday->setTime(14, 14), 'late_minutes' => 314, 'undertime_minutes' => 30, 'status' => TimeLogStatus::LateAndUndertime]);
    TimeLog::factory()->for(($this->named)('Punctual Pia'))->on($yesterday)->create(['shift_id' => $this->day->id]);

    Livewire::actingAs($this->hr)->test(AttendanceLog::class)
        ->call('showOnly', AttendanceLog::LATE)
        ->assertSee('Late Larry')->assertSee('5 hr 14 min late')->assertSee('Left 30 min early')
        ->assertDontSee('Punctual Pia')
        ->assertSet('show', AttendanceLog::LATE);
});

it('shows entries Operations made by hand, which have no selfie', function () {
    $yesterday = CarbonImmutable::parse('2026-10-20');
    TimeLog::factory()->for(($this->named)('Manual Marco'))->on($yesterday)->create(['shift_id' => $this->day->id, 'recorded_by' => $this->hr->id, 'manual_reason' => 'Phone was broken']);

    Livewire::actingAs($this->hr)->test(AttendanceLog::class)
        ->assertSee('Manual Marco')->assertSee('Entered by Operations')->assertSee('Phone was broken');
});

it('ignores an unknown view in the address', function () {
    Livewire::actingAs($this->hr)->test(AttendanceLog::class)->call('showOnly', 'everything')->assertSet('show', AttendanceLog::ALL);
});
