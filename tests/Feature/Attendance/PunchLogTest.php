<?php

use App\Enums\PunchType;
use App\Enums\TimeLogStatus;
use App\Livewire\Attendance\PunchLog;
use App\Models\TimeLog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// My Attendance → Punch Log: one row per shift under a heading per day, clock-in and
// clock-out side by side with their selfie, time and place (like the Attendance Log).

beforeEach(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 14:00')));
afterEach(fn () => CarbonImmutable::setTestNow());

it('shows each of your shifts once, in and out side by side, under its day', function () {
    $guard = employee();
    $yesterday = CarbonImmutable::parse('2026-10-21');
    $closed = TimeLog::factory()->for($guard)->on($yesterday)->create(['clock_in_at' => $yesterday->setTime(9, 20), 'clock_out_at' => $yesterday->setTime(17, 30), 'late_minutes' => 20, 'undertime_minutes' => 30, 'status' => TimeLogStatus::LateAndUndertime]);
    foreach ([[PunchType::ClockIn, $yesterday->setTime(9, 20), 'Saint Peter Street, Baesa'], [PunchType::ClockOut, $yesterday->setTime(17, 30), 'Road 18, Project 8']] as [$type, $at, $address]) {
        $closed->punches()->forceCreate(['type' => $type, 'punched_at' => $at, 'photo_path' => 'punches/x.jpg', 'latitude' => 14.6671, 'longitude' => 121.0162, 'address' => $address]);
    }
    $today = CarbonImmutable::parse('2026-10-22');
    $open = TimeLog::factory()->for($guard)->create(['log_date' => $today, 'clock_in_at' => $today->setTime(9, 0), 'clock_out_at' => null, 'status' => TimeLogStatus::Incomplete]);
    $open->punches()->forceCreate(['type' => PunchType::ClockIn, 'punched_at' => $today->setTime(9, 0), 'photo_path' => 'punches/y.jpg', 'latitude' => 14.6671, 'longitude' => 121.0162, 'address' => 'Saint Peter Street, Baesa']);

    // Someone else's shift never shows.
    $other = employee();
    TimeLog::factory()->for($other)->on($yesterday)->create(['clock_in_at' => $yesterday->setTime(7, 7)]);

    $page = Livewire::actingAs($guard)->test(PunchLog::class)
        ->assertSeeInOrder(['Today · Thu, Oct 22', '9:00 AM', 'Not clocked out yet', 'Yesterday · Wed, Oct 21', '9:20 AM', '20 min late', 'Saint Peter Street, Baesa', '5:30 PM', 'Left 30 min early', 'Road 18, Project 8'])
        ->assertSee('https://www.google.com/maps/search/?api=1&query=14.6671,121.0162')
        ->assertDontSee('7:07 AM');

    expect($page->instance()->logs()->total())->toBe(2); // one row per shift, not one per punch

    // Once the shift has ended (default 09:00–18:00), it no longer says "yet".
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 19:00'));
    Livewire::actingAs($guard)->test(PunchLog::class)->assertSee('No clock-out recorded')->assertDontSee('Not clocked out yet');
});

it('shows a shift Operations entered by hand, which has no selfie', function () {
    $guard = employee();
    TimeLog::factory()->for($guard)->on(CarbonImmutable::parse('2026-10-21'))->create(['recorded_by' => staff()->id, 'manual_reason' => 'Phone was broken']);

    Livewire::actingAs($guard)->test(PunchLog::class)->assertSee('Entered by Operations: Phone was broken');
});
