<?php

namespace App\Services\Attendance;

use App\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * Pure attendance math: no queries, no clock reads. Every input is passed in,
 * so a test can say "a clock-in at 09:15 on a 09:00 + 10 min shift" directly.
 */
final class AttendanceCalculator
{
    /**
     * When the shift that started on $logDate begins and ends. A shift such as
     * 22:00–06:00 ends on the following day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function shiftWindow(Shift $shift, CarbonImmutable $logDate): array
    {
        $start = $this->onDate($logDate, $shift->start_time);
        $end = $this->onDate($logDate, $shift->end_time);

        if ($shift->crossesMidnight()) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    /**
     * Minutes after shift start + grace. Never negative (spec rule 4).
     */
    public function lateMinutes(CarbonImmutable $clockIn, Shift $shift, CarbonImmutable $logDate): int
    {
        [$start] = $this->shiftWindow($shift, $logDate);
        $deadline = $start->addMinutes($shift->grace_minutes);

        return $this->wholeMinutesBetween($deadline, $clockIn);
    }

    /**
     * Minutes before scheduled shift end. Never negative.
     */
    public function undertimeMinutes(CarbonImmutable $clockOut, Shift $shift, CarbonImmutable $logDate): int
    {
        [, $end] = $this->shiftWindow($shift, $logDate);

        return $this->wholeMinutesBetween($clockOut, $end);
    }

    /**
     * Worked minutes beyond the scheduled length. Payroll later caps this at
     * the approved overtime request for the day.
     */
    public function overtimeMinutes(CarbonImmutable $clockIn, CarbonImmutable $clockOut, Shift $shift): int
    {
        $worked = $this->wholeMinutesBetween($clockIn, $clockOut);

        return max(0, $worked - $shift->scheduledMinutes());
    }

    /**
     * Minutes worked between 10:00 PM and 6:00 AM (Labor Code Art. 86), only
     * within the scheduled shift: clocking in early or staying late without
     * approval doesn't earn night differential. Without a shift, the whole
     * clocked time counts.
     */
    public function nightMinutes(CarbonImmutable $clockIn, CarbonImmutable $clockOut, ?Shift $shift, CarbonImmutable $logDate): int
    {
        $from = $clockIn;
        $to = $clockOut;

        if ($shift !== null) {
            [$start, $end] = $this->shiftWindow($shift, $logDate);
            $from = $from->max($start);
            $to = $to->min($end);
        }

        $minutes = 0;

        // Each night runs 22:00 to 06:00 the next day; check the nights that can touch [from, to].
        for ($night = $from->startOfDay()->subDay(); $night->lessThanOrEqualTo($to); $night = $night->addDay()) {
            $nightStart = $night->setTime(22, 0)->max($from);
            $nightEnd = $night->addDay()->setTime(6, 0)->min($to);
            $minutes += $this->wholeMinutesBetween($nightStart, $nightEnd);
        }

        return $minutes;
    }

    /**
     * Full minutes from $from to $to, or 0 when $to is not after $from.
     * intdiv on seconds: 4 min 59 s late is 4 minutes, not 5.
     */
    private function wholeMinutesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 60));
    }

    private function onDate(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $date->setTime($hours, $minutes);
    }
}
