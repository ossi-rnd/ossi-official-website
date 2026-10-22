<?php

namespace App\Queries\Attendance;

use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\TimeLog;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * On-time / late / absent / on-leave headcounts for each day from $start, for
 * $days days (a week by default; the dashboard's 7D / 14D / 30D), plus each
 * day's attendance rate: in ÷ expected (everyone not on leave). Four queries
 * total, never one per day or per employee.
 *
 * @phpstan-type TrendDay array{day: string, date: string, on_time: int, late: int, absent: int, on_leave: int, in: int, expected: int, rate: int|null}
 */
final class WeeklyTrendQuery
{
    /**
     * @return list<TrendDay>
     */
    public function handle(CarbonImmutable $start, ?User $viewer = null, int $days = 7): array
    {
        $end = $start->addDays($days - 1);
        $today = CarbonImmutable::today();

        $employeeIds = User::query()->onPayroll()->when($viewer, fn ($query, User $viewer) => $query->onDashboardOf($viewer))->pluck('id');
        $headcount = $employeeIds->count();

        $logsByDate = TimeLog::query()
            ->whereIn('user_id', $employeeIds)
            ->between($start, $end)
            ->get(['user_id', 'log_date', 'status'])
            ->groupBy(fn (TimeLog $log) => $log->log_date->toDateString());

        $holidays = Holiday::query()->between($start, $end)->pluck('date')
            ->mapWithKeys(fn (CarbonImmutable $date) => [$date->toDateString() => true]);

        $leaves = LeaveRequest::query()
            ->approved()
            ->whereIn('user_id', $employeeIds)
            ->overlapping($start, $end)
            ->get(['user_id', 'start_date', 'end_date']);

        $trend = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $logs = $logsByDate->get($key, collect());
            $logged = $logs->pluck('user_id')->flip();

            $onLeave = $leaves
                ->filter(fn (LeaveRequest $leave) => $leave->start_date->lte($date) && $leave->end_date->gte($date) && ! $logged->has($leave->user_id))
                ->count();

            $isPast = $date->lt($today);
            $in = $logs->count();
            $expected = max(0, $headcount - $onLeave);

            $trend[] = [
                // "Mon" for a week; "Oct 3" for longer ranges.
                'day' => $days <= 7 ? $date->format('D') : $date->format('M j'),
                'date' => $key,
                'on_time' => $logs->filter(fn (TimeLog $log) => ! $log->status->isLate())->count(),
                'late' => $logs->filter(fn (TimeLog $log) => $log->status->isLate())->count(),
                // Only finished, non-holiday days can have absences.
                'absent' => $isPast && ! $holidays->has($key) ? max(0, $headcount - $logged->count() - $onLeave) : 0,
                'on_leave' => $onLeave,
                'in' => $in,
                'expected' => $expected,
                // None for days still to come, or with nobody expected.
                'rate' => $date->gt($today) || $expected === 0 ? null : (int) round($in / $expected * 100),
            ];
        }

        return $trend;
    }
}
