<?php

namespace App\Queries\Attendance;

use App\Models\Holiday;
use App\Models\TimeLog;
use App\Models\User;
use App\Services\Attendance\DayStatusResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One person's attendance, day by day. Three queries per call (logs, holidays,
 * approved leave) whatever the range, and callers bound the range to at most
 * a year, so this stays cheap even for someone with a decade of records.
 *
 * @phpstan-type Day array{date: string, label: string, chartLabel: string, isToday: bool, isHoliday: bool, clockIn: string|null, clockOut: string|null, hours: float|null, status: string}
 * @phpstan-type Summary array{present: int, onTime: int, late: int, undertime: int, absent: int, holiday: int, onLeave: int, totalHours: float, punctualityRate: int}
 * @phpstan-type MonthRow array{ym: string, label: string, daysInRange: int, present: int, onTime: int, late: int, undertime: int, absent: int, holiday: int, onLeave: int, totalHours: float, punctualityRate: int}
 */
final class AttendanceHistoryQuery
{
    /**
     * @return list<Day>
     */
    public function handle(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->buildDays($start, $end, $this->logs($user, $start, $end), $this->holidayDates($start, $end), $this->leaveDates($user, $start, $end), $this->hireDate($user));
    }

    /**
     * Per-month rollups for a year, stopping at the current month.
     *
     * @return list<MonthRow>
     */
    public function monthlyBreakdown(User $user, int $year): array
    {
        $yearStart = CarbonImmutable::today()->setDate($year, 1, 1);
        $yearEnd = $yearStart->isSameYear(today()) ? CarbonImmutable::today() : $yearStart->endOfYear()->startOfDay();

        $logs = $this->logs($user, $yearStart, $yearEnd);
        $holidays = $this->holidayDates($yearStart, $yearEnd);
        $leave = $this->leaveDates($user, $yearStart, $yearEnd);
        $hireDate = $this->hireDate($user);

        $months = [];

        for ($month = 1; $month <= $yearEnd->month; $month++) {
            $monthStart = CarbonImmutable::today()->setDate($year, $month, 1);
            $monthEnd = $monthStart->isSameMonth(today()) ? CarbonImmutable::today() : $monthStart->endOfMonth()->startOfDay();
            $days = $this->buildDays($monthStart, $monthEnd, $logs, $holidays, $leave, $hireDate);

            $months[] = [
                'ym' => $monthStart->format('Y-m'),
                'label' => $monthStart->format('F'),
                'daysInRange' => count(array_filter($days, fn (array $day) => $day['status'] !== 'not_hired')),
                ...$this->summarize($days),
            ];
        }

        return $months;
    }

    /**
     * @param  list<Day>  $days
     * @return Summary
     */
    public function summarize(array $days): array
    {
        $days = collect($days);
        $present = $days->whereNotNull('clockIn')->count();
        $onTime = $days->where('status', 'on_time')->count();

        return [
            'present' => $present,
            'onTime' => $onTime,
            'late' => $days->whereIn('status', ['late', 'late_and_undertime'])->count(),
            'undertime' => $days->whereIn('status', ['undertime', 'late_and_undertime'])->count(),
            'absent' => $days->where('status', 'absent')->count(),
            'holiday' => $days->where('status', 'holiday')->count(),
            'onLeave' => $days->where('status', 'on_leave')->count(),
            'totalHours' => round((float) $days->sum('hours'), 1),
            'punctualityRate' => $present > 0 ? (int) round($onTime / $present * 100) : 0,
        ];
    }

    /**
     * @param  list<MonthRow>  $months
     * @return Summary
     */
    public function summarizeMonths(array $months): array
    {
        $months = collect($months);
        $present = (int) $months->sum('present');
        $onTime = (int) $months->sum('onTime');

        return [
            'present' => $present,
            'onTime' => $onTime,
            'late' => (int) $months->sum('late'),
            'undertime' => (int) $months->sum('undertime'),
            'absent' => (int) $months->sum('absent'),
            'holiday' => (int) $months->sum('holiday'),
            'onLeave' => (int) $months->sum('onLeave'),
            'totalHours' => round((float) $months->sum('totalHours'), 1),
            'punctualityRate' => $present > 0 ? (int) round($onTime / $present * 100) : 0,
        ];
    }

    /**
     * Nobody is "absent" before they were hired.
     */
    private function hireDate(User $user): CarbonImmutable
    {
        $user->loadMissing('employeeProfile');

        return $user->employeeProfile->hire_date ?? $user->created_at->startOfDay();
    }

    /**
     * @return Collection<string, TimeLog>
     */
    private function logs(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $user->timeLogs()
            ->between($start, $end)
            ->get()
            ->keyBy(fn (TimeLog $log) => $log->log_date->toDateString());
    }

    /**
     * @return Collection<string, bool>
     */
    private function holidayDates(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Holiday::query()
            ->between($start, $end)
            ->pluck('date')
            ->mapWithKeys(fn (CarbonImmutable $date) => [$date->toDateString() => true]);
    }

    /**
     * Approved leave expanded into a set of dates, in memory.
     *
     * @return Collection<string, bool>
     */
    private function leaveDates(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $dates = collect();

        $user->leaveRequests()->approved()->overlapping($start, $end)->get(['start_date', 'end_date'])
            ->each(function ($leave) use ($dates, $start, $end) {
                $cursor = $leave->start_date->max($start);
                $last = $leave->end_date->min($end);

                for (; $cursor->lte($last); $cursor = $cursor->addDay()) {
                    $dates->put($cursor->toDateString(), true);
                }
            });

        return $dates;
    }

    /**
     * @param  Collection<string, TimeLog>  $logs
     * @param  Collection<string, bool>  $holidays
     * @param  Collection<string, bool>  $leave
     * @return list<Day>
     */
    private function buildDays(CarbonImmutable $start, CarbonImmutable $end, Collection $logs, Collection $holidays, Collection $leave, CarbonImmutable $hireDate): array
    {
        $days = [];
        $today = CarbonImmutable::today();

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $log = $logs->get($key);
            $worked = $log?->workedMinutes();

            $days[] = [
                'date' => $key,
                'label' => $date->format('D, M j'),
                'chartLabel' => $date->format('n/j'),
                'isToday' => $date->isSameDay($today),
                'isHoliday' => $holidays->has($key),
                'clockIn' => $log?->clock_in_at?->format('g:i A'),
                'clockOut' => $log?->clock_out_at?->format('g:i A'),
                'hours' => $worked !== null ? round($worked / 60, 1) : null,
                'status' => DayStatusResolver::resolve(
                    logStatus: $log?->status->value,
                    isHoliday: $holidays->has($key),
                    isOnLeave: $leave->has($key),
                    isToday: $date->isSameDay($today),
                    isFuture: $date->gt($today),
                    isBeforeHire: $date->lt($hireDate),
                ),
            ];
        }

        return $days;
    }
}
