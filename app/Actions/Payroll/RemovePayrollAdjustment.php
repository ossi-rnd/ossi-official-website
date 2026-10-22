<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\PayrollAdjustment;
use App\Models\PayslipRecord;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes back an adjustment before its payslip is generated. After that the
 * payslip is frozen, so a reversal is a new adjustment on the next payroll.
 * The audit entry keeps what was removed and why it existed.
 */
final class RemovePayrollAdjustment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PayrollAdjustment $adjustment, User $actor): void
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($adjustment, $actor) {
            $locked = PayrollAdjustment::query()->with('employee:id,name')->lockForUpdate()->findOrFail($adjustment->id);

            if (PayslipRecord::query()->where('payroll_run_id', $locked->payroll_run_id)->where('user_id', $locked->user_id)->exists()) {
                throw ValidationException::withMessages(['adjustmentAmount' => 'The payslip is already generated, so this adjustment is part of it. Reverse it on the next payroll.']);
            }

            $this->audit->record(AuditArea::Payroll, 'adjustment.removed', 'Removed the ₱'.number_format(abs((float) $locked->amount), 2)." adjustment on {$locked->employee->name}'s payslip", $locked, [
                'old' => ['amount' => $locked->amount, 'category' => $locked->category->value, 'reason' => $locked->reason, 'status' => $locked->status->value],
            ], causer: $actor);

            $locked->delete();
        });
    }
}
