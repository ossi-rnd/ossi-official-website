<?php

namespace App\Actions\Payroll;

use App\Data\PayslipBreakdown;
use App\Data\RateSchedule;
use App\Enums\AuditArea;
use App\Models\EmployeeDeduction;
use App\Models\PaidLeavePayment;
use App\Models\PayrollAdjustment;
use App\Models\PayrollCarryover;
use App\Models\PayrollRun;
use App\Models\PayslipRecord;
use App\Models\PriorPeriodCorrection;
use App\Models\TimeLog;
use App\Models\User;
use App\Queries\Payroll\PayslipInputsQuery;
use App\Services\AuditLogger;
use App\Services\Payroll\PayslipCalculator;
use App\Services\Payroll\RateScheduleResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Freezes one employee's payslip for a run: every line is saved to
 * payslip_records, and from then on the SAVED figures are shown, even if
 * rates or attendance change later. Loan balances drop by what was deducted.
 *
 * Net pay never goes below ₱0 (PayslipCalculator). What the pay couldn't
 * cover is saved as payroll_carryovers and deducted first on the next payslip.
 */
final class GeneratePayslip
{
    public function __construct(
        private readonly PayslipInputsQuery $inputs,
        private readonly PayslipCalculator $calculator,
        private readonly RateScheduleResolver $rates,
        private readonly AuditLogger $audit,
        private readonly DetectPriorPeriodCorrections $corrections,
        private readonly PayApprovedReimbursements $reimbursements,
        private readonly DetectPaidLeave $paidLeave,
        private readonly PayDueReferralBonuses $referralBonuses,
    ) {}

    /**
     * @param  bool  $correctionsChecked  the caller already ran DetectPriorPeriodCorrections for the run (Generate all)
     */
    public function handle(PayrollRun $run, User $employee, User $actor, bool $correctionsChecked = false): PayslipRecord
    {
        // Pay that changed on an earlier cut-off is decided before this payslip is frozen.
        if (! $correctionsChecked) {
            $this->corrections->handle($run, $employee->newCollection([$employee]));
            $this->paidLeave->handle($run, $employee->newCollection([$employee]));
        }

        try {
            return DB::transaction(function () use ($run, $employee, $actor) {
                // Locking the run serializes generation with "advance to approved".
                $lockedRun = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

                if (! $lockedRun->status->canGeneratePayslips()) {
                    throw $this->reject('Payslips can only be generated while the run is processing.');
                }

                if (PayslipRecord::query()->where('payroll_run_id', $lockedRun->id)->where('user_id', $employee->id)->exists()) {
                    throw $this->reject("{$employee->name}'s payslip has already been generated for this run.");
                }

                if (PayrollAdjustment::query()->where('payroll_run_id', $lockedRun->id)->where('user_id', $employee->id)->pending()->exists()) {
                    throw $this->reject("{$employee->name} has an adjustment awaiting approval. Approve or reject it first.");
                }

                $correction = PriorPeriodCorrection::query()->with('fromRun')->where('payroll_run_id', $lockedRun->id)->where('user_id', $employee->id)->suggested()->first();
                if ($correction !== null) {
                    throw $this->reject("{$employee->name}'s pay for {$correction->fromRun->periodLabel()} changed after it was paid (".($correction->amount >= 0 ? '+' : '−').'₱'.number_format(abs((float) $correction->amount), 2).'). Accept or dismiss it under "Corrections from earlier cut-offs" first.');
                }

                if (PaidLeavePayment::query()->where('payroll_run_id', $lockedRun->id)->where('user_id', $employee->id)->suggested()->exists()) {
                    throw $this->reject("{$employee->name} has paid leave in this cut-off. Accept or dismiss it under \"Paid leave this cut-off\" first.");
                }

                if (($earlier = $this->blockingEarlierRun($lockedRun, $employee)) !== null) {
                    throw $this->reject("Generate {$employee->name}'s {$earlier->periodLabel()} payslip first: this one balances the month's SSS, PhilHealth and Pag-IBIG against it.");
                }

                $employee->loadMissing('employeeProfile');

                // Approved reimbursements become lines on this payslip before it's computed.
                $this->reimbursements->handle($lockedRun, $employee, $actor);
                // So do referral bonuses that fell due by the end of this cut-off.
                $this->referralBonuses->handle($lockedRun, $employee, $actor);

                $input = $this->inputs->handle($lockedRun, collect([$employee]))[$employee->id];

                // No attendance is fine when the law pays their leave (e.g. paternity leave the whole cut-off).
                $paidLeave = PaidLeavePayment::query()->where('payroll_run_id', $lockedRun->id)->where('user_id', $employee->id)->where('status', PaidLeavePayment::ACCEPTED)->exists();

                if ($input->daysWorked === 0 && ! $paidLeave) {
                    throw $this->reject("{$employee->name} has no attendance in this period, so there is nothing to pay.");
                }

                $schedule = $this->rates->forDate($lockedRun->start_date);

                if (! self::hasRate($input->dailyRate, $schedule, $input->isOfficeStaff)) {
                    throw $this->reject("{$employee->name} has no daily rate yet. Accounting sets it on Daily Rates (a personal rate, or the rate for their position).");
                }

                $breakdown = $this->calculator->calculate($input, $schedule);

                $record = new PayslipRecord;
                $record->forceFill([
                    ...$breakdown->toRecordAttributes(),
                    'payroll_run_id' => $lockedRun->id,
                    'user_id' => $employee->id,
                    'generated_at' => now(),
                    'generated_by' => $actor->id,
                ])->save();

                // Balances drop by what was ACTUALLY deducted: when the pay ran
                // out, an installment may be partly or not at all taken.
                foreach ($breakdown->loanLines as $line) {
                    if (isset($line['deduction_id']) && $line['amount'] > 0) {
                        $this->consumeInstallment($line['deduction_id'], $line['amount']);
                    }
                }

                $this->settleCarryovers($lockedRun, $employee, $breakdown);

                $this->audit->record(AuditArea::Payroll, 'payslip.generated', "Generated payslip of {$employee->name} for {$lockedRun->periodLabel()}", $record, [
                    'new' => [
                        'gross_pay' => $record->gross_pay,
                        'total_deductions' => $record->total_deductions,
                        'net_pay' => $record->net_pay,
                        'rate_schedule' => $record->rate_schedule,
                        'carried_in' => $record->carryover_deductions,
                        'carried_forward' => $breakdown->shortfall() > 0 ? number_format($breakdown->shortfall(), 2, '.', '') : null,
                    ],
                ], causer: $actor);

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            // A second request raced past the exists() check; the unique index stopped it.
            throw $this->reject("{$employee->name}'s payslip has already been generated for this run.");
        }
    }

    /**
     * Reduces the remaining balance by what this payslip took, never below 0.
     * A fully paid loan switches itself off.
     */
    private function consumeInstallment(int $deductionId, float $amount): void
    {
        $deduction = EmployeeDeduction::query()->lockForUpdate()->find($deductionId);

        if ($deduction === null || $deduction->remaining_balance === null) {
            return;
        }

        $remaining = max(0.0, round((float) $deduction->remaining_balance - $amount, 2));

        $deduction->forceFill([
            'remaining_balance' => number_format($remaining, 2, '.', ''),
            'is_active' => $remaining > 0,
        ])->save();
    }

    /**
     * The month's LAST cutoff deducts "monthly contribution − what earlier
     * cutoffs were due", so an earlier cutoff the employee worked must be
     * frozen first; otherwise it could later deduct something different from
     * the estimate this payslip subtracted. Returns that earlier run, if any.
     */
    /**
     * A rate of their own, or a company default above ₱0. From 2026 the
     * default is ₱0: nobody is paid on a made-up rate.
     */
    public static function hasRate(?float $dailyRate, RateSchedule $schedule, bool $isOfficeStaff): bool
    {
        return ($dailyRate ?? $schedule->dailyRate($isOfficeStaff)) > 0;
    }

    public function blockingEarlierRun(PayrollRun $run, User $employee): ?PayrollRun
    {
        if (! $run->end_date->isSameDay($run->end_date->endOfMonth()) || ! $this->rates->forDate($run->start_date)->usesStatutoryFormulas()) {
            return null;
        }

        return PayrollRun::query()
            ->whereKeyNot($run->id)
            ->whereDate('start_date', '<', $run->start_date->toDateString())
            ->whereDate('end_date', '>=', $run->end_date->startOfMonth()->toDateString())
            ->whereDate('end_date', '<=', $run->end_date->toDateString())
            ->orderBy('start_date')
            ->get()
            ->first(fn (PayrollRun $earlier) => $this->rates->forDate($earlier->start_date)->usesStatutoryFormulas()
                && TimeLog::query()->where('user_id', $employee->id)->between($earlier->start_date, $earlier->end_date)->exists()
                && ! PayslipRecord::query()->where('payroll_run_id', $earlier->id)->where('user_id', $employee->id)->exists());
    }

    /**
     * Every carry-over this payslip brought in is settled here, even if only
     * partly covered: its unpaid remainder is in the shortfall list, and so
     * becomes a NEW carry-over from this run. One rule, no partial rows.
     */
    private function settleCarryovers(PayrollRun $run, User $employee, PayslipBreakdown $breakdown): void
    {
        $broughtIn = array_column($breakdown->carryoverLines, 'carryover_id');

        if ($broughtIn !== []) {
            PayrollCarryover::query()
                ->whereIn('id', $broughtIn)
                ->whereNull('settled_in_payroll_run_id')
                ->lockForUpdate()
                ->get()
                ->each(fn (PayrollCarryover $carryover) => $carryover->forceFill(['settled_in_payroll_run_id' => $run->id])->save());
        }

        foreach ($breakdown->shortfallLines as $shortfall) {
            if (! $shortfall['carries']) {
                continue; // a loan installment: it stays in the loan's balance
            }

            // A carried line already names its first period; don't stack "(from …)" twice.
            $label = str_contains($shortfall['label'], '(from ') ? $shortfall['label'] : "{$shortfall['label']} (from {$run->periodLabel()})";

            $carryover = new PayrollCarryover;
            $carryover->forceFill([
                'user_id' => $employee->id,
                'from_payroll_run_id' => $run->id,
                'label' => $label,
                'amount' => number_format($shortfall['amount'], 2, '.', ''),
            ])->save();
        }
    }

    private function reject(string $message): ValidationException
    {
        return ValidationException::withMessages(['payslip' => $message]);
    }
}
