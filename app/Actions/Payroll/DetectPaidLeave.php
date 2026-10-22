<?php

namespace App\Actions\Payroll;

use App\Enums\LeaveType;
use App\Models\LeaveRequest;
use App\Models\PaidLeavePayment;
use App\Models\PayrollRun;
use App\Models\PayslipRecord;
use App\Models\User;
use App\Queries\People\EffectiveRatesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Suggests the leave the law pays on a payroll run: each approved maternity,
 * paternity, solo parent, VAWC or special leave for women that overlaps the
 * cut-off, for Accounting to accept or dismiss (PaidLeaveSuggestions).
 *
 *   days    the leave's days inside this cut-off, capped by what the law gives
 *           (LeaveType::maxDays) less what earlier runs already suggested or paid
 *   amount  days × the daily rate on the cut-off's start; maternity has none (the
 *           SSS benefit is computed from the SSS salary credit: Accounting enters it)
 *
 * Run when a run starts processing, before "Generate all", and before one payslip.
 * Idempotent: one row per leave and run, never re-suggested once decided.
 */
final class DetectPaidLeave
{
    public function __construct(private readonly EffectiveRatesQuery $rates) {}

    /**
     * @param  Collection<int, User>|null  $employees  only these (one payslip); null: everyone
     * @return int how many are waiting for Accounting on this run
     */
    public function handle(PayrollRun $run, ?Collection $employees = null): int
    {
        $start = CarbonImmutable::parse($run->start_date);
        $end = CarbonImmutable::parse($run->end_date);
        $ids = $employees?->modelKeys();

        $leaves = LeaveRequest::query()->approved()
            ->whereIn('type', LeaveType::paidByLawValues())
            ->overlapping($start, $end)
            ->when($ids !== null, fn ($query) => $query->whereIn('user_id', $ids))
            ->with('user.employeeProfile')
            ->get();

        foreach ($leaves as $leave) {
            $profile = $leave->user->employeeProfile;

            // Already decided or suggested on this run, or the payslip is already frozen.
            if ($profile === null
                || PaidLeavePayment::query()->where('leave_request_id', $leave->id)->where('payroll_run_id', $run->id)->exists()
                || PayslipRecord::query()->where('payroll_run_id', $run->id)->where('user_id', $leave->user_id)->exists()) {
                continue;
            }

            $from = $leave->start_date->max($start);
            $to = $leave->end_date->min($end);
            $days = (int) $from->diffInDays($to) + 1;

            // What the law gives, less what earlier runs already took (not dismissed).
            if (($max = $leave->type->maxDays()) !== null) {
                $used = (int) PaidLeavePayment::query()->where('leave_request_id', $leave->id)->where('status', '!=', PaidLeavePayment::DISMISSED)->sum('days');
                $days = min($days, $max - $used);
            }

            if ($days <= 0) {
                continue;
            }

            $rate = $leave->type->paidAsSssBenefit() ? null : ($this->rates->handle([$profile], $start)[$profile->id]->rate ?? 0.0);

            $payment = new PaidLeavePayment;
            $payment->forceFill([
                'payroll_run_id' => $run->id,
                'user_id' => $leave->user_id,
                'leave_request_id' => $leave->id,
                'days' => $days,
                'daily_rate' => $rate,
                'amount' => $rate !== null ? round($days * $rate, 2) : null,
            ])->save();
        }

        return PaidLeavePayment::query()->where('payroll_run_id', $run->id)
            ->when($ids !== null, fn ($query) => $query->whereIn('user_id', $ids))
            ->suggested()->count();
    }
}
