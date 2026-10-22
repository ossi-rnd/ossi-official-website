<?php

use App\Models\Shift;
use App\Services\Attendance\AttendanceCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function dayShift(): Shift
{
    return new Shift(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 10]);
}

function nightShift(): Shift
{
    return new Shift(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => 10]);
}

it('counts late minutes from shift start plus grace, never negative', function (string $clockIn, int $expected) {
    $date = CarbonImmutable::parse('2026-10-08');

    expect((new AttendanceCalculator)->lateMinutes(CarbonImmutable::parse("2026-10-08 {$clockIn}"), dayShift(), $date))->toBe($expected);
})->with([
    'early' => ['08:45:00', 0],
    'inside grace' => ['09:10:00', 0],
    'spec: start + grace + 5 min = 5 late' => ['09:15:00', 5],
    '4 min 59 s is 4 minutes' => ['09:14:59', 4],
    'an hour late' => ['10:10:00', 60],
]);

it('counts undertime before shift end', function () {
    $date = CarbonImmutable::parse('2026-10-08');

    expect((new AttendanceCalculator)->undertimeMinutes(CarbonImmutable::parse('2026-10-08 17:30'), dayShift(), $date))->toBe(30)
        ->and((new AttendanceCalculator)->undertimeMinutes(CarbonImmutable::parse('2026-10-08 18:05'), dayShift(), $date))->toBe(0);
});

it('counts overtime as worked minutes beyond the scheduled length', function () {
    $calculator = new AttendanceCalculator;

    expect($calculator->overtimeMinutes(CarbonImmutable::parse('2026-10-08 09:00'), CarbonImmutable::parse('2026-10-08 20:00'), dayShift()))->toBe(120)
        ->and($calculator->overtimeMinutes(CarbonImmutable::parse('2026-10-08 09:00'), CarbonImmutable::parse('2026-10-08 17:00'), dayShift()))->toBe(0);
});

it('handles a shift that crosses midnight', function () {
    $calculator = new AttendanceCalculator;
    $date = CarbonImmutable::parse('2026-10-08');

    [$start, $end] = $calculator->shiftWindow(nightShift(), $date);

    expect($start->toDateTimeString())->toBe('2026-10-08 22:00:00')
        ->and($end->toDateTimeString())->toBe('2026-10-09 06:00:00')
        ->and(nightShift()->scheduledMinutes())->toBe(480)
        ->and($calculator->undertimeMinutes(CarbonImmutable::parse('2026-10-09 05:00'), nightShift(), $date))->toBe(60);
});

it('counts the minutes worked 10 PM–6 AM inside the shift', function (string $shiftStart, string $shiftEnd, string $in, string $out, int $expected) {
    $shift = new Shift(['name' => 'Test', 'start_time' => $shiftStart, 'end_time' => $shiftEnd, 'grace_minutes' => 0]);
    $day = CarbonImmutable::parse('2026-10-05');
    $at = fn (string $time) => str_starts_with($time, '+') ? $day->addDay()->setTimeFromTimeString(substr($time, 1)) : $day->setTimeFromTimeString($time);

    expect((new AttendanceCalculator)->nightMinutes($at($in), $at($out), $shift, $day))->toBe($expected);
})->with([
    'overnight 22:00–06:00, full night' => ['22:00', '06:00', '21:50', '+06:10', 480],
    '18:00–06:00 post: only 8 of 12 hours' => ['18:00', '06:00', '18:00', '+06:00', 480],
    'evening 14:00–22:00: none' => ['14:00', '22:00', '14:00', '22:30', 0],
    'evening 16:00–00:00: two hours' => ['16:00', '00:00', '16:00', '+00:00', 120],
    'day shift, clocked in at 05:00 for 06:00: none (outside the shift)' => ['06:00', '14:00', '05:00', '14:00', 0],
    'left the night shift at 02:00' => ['22:00', '06:00', '22:00', '+02:00', 240],
    'day shift 09:00–18:00' => ['09:00', '18:00', '09:00', '18:00', 0],
]);

it('counts all clocked night time when no shift is known', function () {
    $day = CarbonImmutable::parse('2026-10-05');

    expect((new AttendanceCalculator)->nightMinutes($day->setTime(20, 0), $day->addDay()->setTime(2, 0), null, $day))->toBe(240);
});
