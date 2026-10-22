<?php

namespace App\Queries\Payroll;

use App\Data\LateDay;
use App\Models\PayrollRun;
use App\Models\TimeLog;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The days behind a payslip's "Late" line. Reads the same time_logs.late_minutes
 * that PayslipInputsQuery sums, over the same run dates, so the list always adds
 * up to the minutes on the payslip.
 */
final class LateDaysQuery
{
    /**
     * @return list<LateDay>
     */
    public function handle(PayrollRun $run, User $user): array
    {
        return array_values(TimeLog::query()
            ->where('user_id', $user->id)
            ->between($run->start_date, $run->end_date)
            ->where('late_minutes', '>', 0)
            ->with('shift:id,name,start_time,grace_minutes')
            ->orderBy('log_date')
            ->get(['id', 'shift_id', 'log_date', 'clock_in_at', 'late_minutes'])
            ->map(fn (TimeLog $log) => new LateDay(
                date: $log->log_date,
                shiftName: $log->shift?->name,
                shiftStart: $log->shift !== null ? CarbonImmutable::createFromFormat('H:i', substr($log->shift->start_time, 0, 5))?->format('g:i A') : null,
                graceMinutes: $log->shift->grace_minutes ?? 0,
                clockInAt: $log->clock_in_at,
                lateMinutes: $log->late_minutes,
            ))
            ->all());
    }
}
