<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AdjustmentCategory;
use App\Enums\AdjustmentStatus;
use App\Enums\AuditArea;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Models\PayslipRecord;
use App\Models\TimeLog;
use App\Models\User;
use App\Notifications\PayrollAdjustmentAwaitingApproval;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A correction on one payslip, as its own line with a reason: never an edit
 * of the calculated figures.
 *
 * They apply at once: Accounting has full control of payroll. Only if
 * payroll.adjustments.approval_threshold is set do adjustments of that size
 * wait for a second person (approve-payroll-adjustments), unless the creator
 * holds that gate already.
 */
final class AddPayrollAdjustment
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  float  $amount  signed: positive adds to pay, negative is deducted
     * @param  bool  $forPaidLeave  from AcceptPaidLeave: leave the law pays makes them due a payslip
     *                              even with no attendance (e.g. paternity leave the whole cut-off)
     */
    public function handle(PayrollRun $run, User $employee, AdjustmentCategory $category, float $amount, string $reason, User $actor, bool $forPaidLeave = false): PayrollAdjustment
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        $amount = round($amount, 2);

        $errors = array_filter([
            'adjustmentAmount' => match (true) {
                $amount === 0.0 => 'Enter an amount.',
                $amount > 0 && ! $category->allowsAddition() => "A {$category->label()} can only be deducted.",
                $amount < 0 && ! $category->allowsDeduction() => "A {$category->label()} can only add to pay.",
                default => null,
            },
            'adjustmentReason' => mb_strlen(trim($reason)) < 5 ? 'Explain the adjustment (at least a few words).' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($run, $employee, $category, $amount, $reason, $actor, $forPaidLeave) {
            // Locked with generation: an adjustment can't slip in while the payslip is being frozen.
            $lockedRun = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! $lockedRun->status->canGeneratePayslips()) {
                throw ValidationException::withMessages(['adjustmentAmount' => 'Adjustments can only be added while the run is processing.']);
            }

            if (PayslipRecord::query()->where('payroll_run_id', $lockedRun->id)->where('user_id', $employee->id)->exists()) {
                throw ValidationException::withMessages(['adjustmentAmount' => 'This payslip is already generated. Add the correction to the next payroll instead.']);
            }

            if (! $forPaidLeave && ! TimeLog::query()->where('user_id', $employee->id)->between($lockedRun->start_date, $lockedRun->end_date)->exists()) {
                throw ValidationException::withMessages(['adjustmentAmount' => 'No attendance in this period, so there will be no payslip to adjust. Add it to a period they worked.']);
            }

            // Off by default (null): Accounting's adjustments apply at once.
            $threshold = config('payroll.adjustments.approval_threshold');
            $needsApproval = $threshold !== null
                && abs($amount) >= (float) $threshold
                && ! $actor->can(Ability::ApprovePayrollAdjustments->value);

            $adjustment = new PayrollAdjustment;
            $adjustment->forceFill([
                'payroll_run_id' => $lockedRun->id,
                'user_id' => $employee->id,
                'category' => $category,
                'amount' => number_format($amount, 2, '.', ''),
                'reason' => trim($reason),
                'status' => $needsApproval ? AdjustmentStatus::Pending : AdjustmentStatus::Approved,
                'created_by' => $actor->id,
                'reviewed_by' => $needsApproval ? null : $actor->id,
                'reviewed_at' => $needsApproval ? null : CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditArea::Payroll, 'adjustment.added', ($amount > 0 ? 'Added ₱' : 'Deducted ₱').number_format(abs($amount), 2)." ({$category->label()}) on {$employee->name}'s payslip for {$lockedRun->periodLabel()}", $adjustment, [
                'amount' => $amount,
                'category' => $category->value,
                'reason' => $adjustment->reason,
                'status' => $adjustment->status->value,
            ], causer: $actor);

            if ($needsApproval) {
                $approvers = User::query()->notifiable(Ability::ApprovePayrollAdjustments->roles())->whereKeyNot($actor->id)->get();
                Notification::send($approvers, new PayrollAdjustmentAwaitingApproval($adjustment));
            }

            return $adjustment;
        });
    }
}
