<?php

namespace App\Actions\Equipment;

use App\Actions\Payroll\SaveEmployeeDeduction;
use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Enums\EquipmentStatus;
use App\Models\EquipmentIssue;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting sets how much each payslip takes for a uniform that was issued (final; the owner's
 * choice, 2026-10-23): Operations or Admin hand it over, Accounting decides the money. The
 * price is what it was issued at. It becomes a deduction the employee must authorize on My
 * Payslips (Labor Code Art. 113): they're asked there, and nothing is taken until they do.
 */
final class SetUniformCharge
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SaveEmployeeDeduction $saveDeduction,
    ) {}

    public function handle(User $accountant, EquipmentIssue $issue, float $installment): EquipmentIssue
    {
        if (! $accountant->can(Ability::ManagePayroll->value) || $accountant->id === $issue->user_id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($accountant, $issue, $installment) {
            $locked = EquipmentIssue::query()->with('user')->lockForUpdate()->findOrFail($issue->id);
            $total = $locked->total();

            if (! $locked->charged || $locked->status === EquipmentStatus::Requested || $locked->status === EquipmentStatus::Rejected || $locked->employee_deduction_id !== null) {
                throw ValidationException::withMessages(['chargeInstallment' => 'This one\'s charge is already set.']);
            }

            if ($installment <= 0 || $installment > $total) {
                throw ValidationException::withMessages(['chargeInstallment' => 'Per payslip: more than ₱0 and at most the total, ₱'.number_format($total, 2).'.']);
            }

            // SaveEmployeeDeduction asks the employee (DeductionAuthorizationNotice) and audits it.
            $deduction = $this->saveDeduction->handle($locked->user, $locked->describe(), DeductionType::Uniform, $installment, $total, $accountant, DeductionAuthorizationBasis::Employee);
            $deduction->forceFill(['remaining_balance' => number_format($total, 2, '.', '')])->save();
            $locked->forceFill(['employee_deduction_id' => $deduction->id])->save();

            $this->audit->record(AuditArea::Payroll, 'equipment.charge_set', "Set {$locked->user->name}'s {$locked->describe()}: ₱".number_format($installment, 2).' per payslip, ₱'.number_format($total, 2).' in all', $locked, [
                'new' => ['installment' => $installment, 'total' => $total],
            ], causer: $accountant);

            return $locked;
        });
    }
}
