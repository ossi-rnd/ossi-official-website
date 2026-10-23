<?php

namespace App\Queries\Payroll;

use App\Data\AdjustmentLine;
use App\Data\CarryoverLine;
use App\Data\LoanLine;
use App\Data\MonthToDate;
use App\Data\PayslipInput;
use App\Data\ResolvedRate;
use App\Enums\HolidayType;
use App\Enums\RateSource;
use App\Models\EmployeeDeduction;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Models\PayrollAdjustment;
use App\Models\PayrollCarryover;
use App\Models\PayrollRun;
use App\Models\TimeLog;
use App\Models\User;
use App\Queries\People\EffectiveRatesQuery;
use App\Services\Attendance\AttendanceCalculator;
use Illuminate\Support\Collection;

/**
 * Gathers calculator inputs for many employees in seven queries total,
 * whatever the headcount (no query per employee).
 */
final class PayslipInputsQuery
{
    /**
     * @param  Collection<int, User>  $users  with employeeProfile loaded
     * @return array<int, PayslipInput> keyed by user id
     */
    public function handle(PayrollRun $run, Collection $users): array
    {
        $ids = $users->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $logsByUser = TimeLog::query()
            ->whereIn('user_id', $ids)
            ->between($run->start_date, $run->end_date)
            ->with('shift')
            ->get(['user_id', 'shift_id', 'log_date', 'clock_in_at', 'clock_out_at', 'late_minutes', 'overtime_minutes'])
            ->groupBy('user_id');

        $approvedByUser = OvertimeRequest::query()
            ->approved()
            ->whereIn('user_id', $ids)
            ->whereDate('date', '>=', $run->start_date->toDateString())
            ->whereDate('date', '<=', $run->end_date->toDateString())
            ->get(['user_id', 'date', 'approved_minutes'])
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn (OvertimeRequest $row) => [$row->date->toDateString() => (int) $row->approved_minutes]));

        /** @var Collection<string, HolidayType> $holidayTypes */
        $holidayTypes = Holiday::query()
            ->between($run->start_date, $run->end_date)
            ->get(['date', 'type'])
            ->mapWithKeys(fn (Holiday $holiday) => [$holiday->date->toDateString() => $holiday->type]);

        // Not one still waiting for the employee's authorization (Art. 113).
        $loansByUser = EmployeeDeduction::query()
            ->takenFromPay()
            ->whereIn('user_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        // Shortfalls of earlier payslips, owed on this one.
        $carryoversByUser = PayrollCarryover::query()
            ->owedBefore($run)
            ->whereIn('user_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        // Only APPROVED adjustments reach a payslip; pending ones wait for a second person.
        $adjustmentsByUser = PayrollAdjustment::query()
            ->approved()
            ->where('payroll_run_id', $run->id)
            ->whereIn('user_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $monthToDate = $this->monthToDate($run, $users);

        // Personal rate, else the position's rate, in force when the period starts.
        // The schedule default stays null here: the calculator applies it itself.
        $ratesByProfile = array_map(
            fn (ResolvedRate $rate) => $rate->source === RateSource::Default ? null : $rate->rate,
            app(EffectiveRatesQuery::class)->handle($users->map(fn (User $user) => $user->employeeProfile)->filter()->values()->all(), $run->start_date),
        );

        $inputs = [];

        foreach ($users as $user) {
            /** @var Collection<int, TimeLog> $logs */
            $logs = $logsByUser->get($user->id, collect());
            /** @var Collection<string, int> $approved */
            $approved = $approvedByUser->get($user->id, collect());
            /** @var Collection<int, EmployeeDeduction> $loans */
            $loans = $loansByUser->get($user->id, collect());
            /** @var Collection<int, PayrollCarryover> $carryovers */
            $carryovers = $carryoversByUser->get($user->id, collect());
            /** @var Collection<int, PayrollAdjustment> $adjustments */
            $adjustments = $adjustmentsByUser->get($user->id, collect());

            $inputs[$user->id] = new PayslipInput(
                isOfficeStaff: (bool) $user->employeeProfile?->is_office_staff,
                nightMinutes: $this->nightMinutes($logs),
                daysWorked: $logs->count(),
                lateMinutes: (int) $logs->sum('late_minutes'),
                overtimeMinutes: $this->payableOvertime($logs, $approved),
                holidayDaysWorked: $this->holidayDaysWorked($logs, $holidayTypes),
                loans: $this->loanLines($loans),
                carryovers: array_values($carryovers->map(fn (PayrollCarryover $carryover) => new CarryoverLine($carryover->id, $carryover->label, (float) $carryover->amount))->all()),
                adjustments: array_values($adjustments->map(fn (PayrollAdjustment $adjustment) => new AdjustmentLine($adjustment->id, $adjustment->lineLabel(), (float) $adjustment->amount, $adjustment->category->isTaxable()))->all()),
                monthToDate: $monthToDate[$user->id],
                dailyRate: $ratesByProfile[$user->employeeProfile->id ?? 0] ?? null,
            );
        }

        return $inputs;
    }

    /**
     * Night minutes over the period, from each day's actual clock-in and
     * clock-out within its shift. A day still open (no clock-out) earns none yet.
     *
     * @param  Collection<int, TimeLog>  $logs
     */
    private function nightMinutes(Collection $logs): int
    {
        $calculator = app(AttendanceCalculator::class);

        return (int) $logs->sum(fn (TimeLog $log) => $log->clock_in_at !== null && $log->clock_out_at !== null
            ? $calculator->nightMinutes($log->clock_in_at, $log->clock_out_at, $log->shift, $log->log_date)
            : 0);
    }

    /**
     * Contributions are monthly. A run that ends on the month's last day is
     * its FINAL cutoff: it needs what the month's earlier runs were due, so
     * it can deduct "monthly − already due". Earlier runs are read through
     * ResolvePayslips: the frozen payslip when generated, else its estimate.
     * (Resolved lazily: ResolvePayslips itself depends on this class.)
     *
     * @param  Collection<int, User>  $users
     * @return array<int, MonthToDate> keyed by user id
     */
    private function monthToDate(PayrollRun $run, Collection $users): array
    {
        $isFinal = $run->end_date->isSameDay($run->end_date->endOfMonth());
        $empty = $users->mapWithKeys(fn (User $user) => [$user->id => new MonthToDate(isFinalCutoff: $isFinal)])->all();

        if (! $isFinal) {
            return $empty;
        }

        $earlierRuns = PayrollRun::query()
            ->whereKeyNot($run->id)
            ->whereDate('start_date', '<', $run->start_date->toDateString())
            ->whereDate('end_date', '>=', $run->end_date->startOfMonth()->toDateString())
            ->whereDate('end_date', '<=', $run->end_date->toDateString())
            ->get();

        if ($earlierRuns->isEmpty()) {
            return $empty;
        }

        $resolver = app(ResolvePayslips::class);
        $months = $empty;

        foreach ($earlierRuns as $earlierRun) {
            foreach ($resolver->handle($earlierRun, $users) as $userId => $payslip) {
                $earlier = $payslip->contributions;

                // No payslip there (no attendance), or one from before the formulas.
                if ($earlier === [] || (! $payslip->isGenerated && $payslip->daysWorked === 0)) {
                    continue;
                }

                $sofar = $months[$userId];
                $months[$userId] = new MonthToDate(
                    isFinalCutoff: true,
                    sss: self::add($sofar->sss, $earlier['sss']),
                    philhealth: self::add($sofar->philhealth, $earlier['philhealth']),
                    pagibig: self::add($sofar->pagibig, $earlier['pagibig']),
                );
            }
        }

        return $months;
    }

    /**
     * @param  array{base: float, employee: float, employer: float, ec: float}  $a
     * @param  array{base: float, employee: float, employer: float, ec: float}  $b
     * @return array{base: float, employee: float, employer: float, ec: float}
     */
    private static function add(array $a, array $b): array
    {
        return [
            'base' => $a['base'] + $b['base'],
            'employee' => $a['employee'] + $b['employee'],
            'employer' => $a['employer'] + $b['employer'],
            'ec' => $a['ec'] + $b['ec'],
        ];
    }

    /**
     * Per day, the smaller of logged and approved overtime. No approved request = 0.
     *
     * @param  Collection<int, TimeLog>  $logs
     * @param  Collection<string, int>  $approvedByDate
     */
    private function payableOvertime(Collection $logs, Collection $approvedByDate): int
    {
        return (int) $logs->sum(fn (TimeLog $log) => min($log->overtime_minutes, $approvedByDate->get($log->log_date->toDateString(), 0)));
    }

    /**
     * Only holidays the employee actually has a time log on: the premium is
     * for WORKING the holiday.
     *
     * @param  Collection<int, TimeLog>  $logs
     * @param  Collection<string, HolidayType>  $holidayTypes
     * @return array<string, int>
     */
    private function holidayDaysWorked(Collection $logs, Collection $holidayTypes): array
    {
        $tally = [];

        foreach ($logs as $log) {
            $type = $holidayTypes->get($log->log_date->toDateString());

            if ($type !== null) {
                $tally[$type->value] = ($tally[$type->value] ?? 0) + 1;
            }
        }

        return $tally;
    }

    /**
     * @param  Collection<int, EmployeeDeduction>  $deductions
     * @return list<LoanLine>
     */
    private function loanLines(Collection $deductions): array
    {
        return array_values($deductions
            ->filter(fn (EmployeeDeduction $deduction) => $deduction->amountDue() > 0)
            ->map(fn (EmployeeDeduction $deduction) => new LoanLine(
                deductionId: $deduction->id,
                label: $deduction->label,
                type: $deduction->type->value,
                amount: round($deduction->amountDue(), 2),
                remainingBalance: $deduction->remaining_balance !== null ? (float) $deduction->remaining_balance : null,
            ))
            ->all());
    }
}
