<?php

namespace App\Actions\Payroll;

use App\Enums\AdjustmentCategory;
use App\Enums\AdjustmentStatus;
use App\Enums\AuditArea;
use App\Enums\ReimbursementStatus;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Called by GeneratePayslip, inside its transaction, before the figures are
 * computed: every approved reimbursement of this employee not yet paid becomes a
 * non-taxable Reimbursement line on this payslip. Approved already by the
 * Executive, so it's added as approved (no second check), and marked paid.
 */
final class PayApprovedReimbursements
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return int how many were added
     */
    public function handle(PayrollRun $run, User $employee, User $actor): int
    {
        $added = 0;

        foreach (Reimbursement::query()->toPay()->where('user_id', $employee->id)->lockForUpdate()->orderBy('id')->get() as $reimbursement) {
            $adjustment = new PayrollAdjustment;
            $adjustment->forceFill([
                'payroll_run_id' => $run->id,
                'user_id' => $employee->id,
                'category' => AdjustmentCategory::Reimbursement,
                'amount' => $reimbursement->amount,
                'reason' => "{$reimbursement->category->short()}, {$reimbursement->spent_on->format('M j')}: ".Str::limit($reimbursement->purpose, 80),
                'status' => AdjustmentStatus::Approved,
                'created_by' => $actor->id,
                'reviewed_by' => $reimbursement->decided_by,
                'reviewed_at' => $reimbursement->decided_at ?? CarbonImmutable::now(),
            ])->save();

            $reimbursement->forceFill(['status' => ReimbursementStatus::Paid, 'payroll_adjustment_id' => $adjustment->id, 'paid_in_run_id' => $run->id])->save();
            $this->audit->record(AuditArea::Payroll, 'reimbursement.paid', "Paid {$employee->name}'s reimbursement of ₱".number_format((float) $reimbursement->amount, 2)." on {$run->periodLabel()}", $reimbursement, causer: $actor);
            $added++;
        }

        return $added;
    }
}
