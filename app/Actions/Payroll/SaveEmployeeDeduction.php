<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Notifications\DeductionAuthorizationNotice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Adds a loan, cash advance or other recurring deduction for an employee.
 * Balance-tracked types start with remaining_balance = the full amount.
 *
 * Labor Code Art. 113: a deduction from pay needs a basis ($basis). Asked in the
 * app, nothing is taken until the employee authorizes it on My Payslips (they're
 * told); signed on paper, or a government loan deducted by law, it applies now.
 */
final class SaveEmployeeDeduction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $employee, string $label, DeductionType $type, float $installment, ?float $originalAmount, User $actor, DeductionAuthorizationBasis $basis): EmployeeDeduction
    {
        return DB::transaction(function () use ($employee, $label, $type, $installment, $originalAmount, $actor, $basis) {
            $deduction = new EmployeeDeduction([
                'label' => $label,
                'type' => $type,
                'installment_amount' => $installment,
                'original_amount' => $originalAmount,
            ]);
            $deduction->user()->associate($employee);
            $deduction->remaining_balance = $type->tracksBalance() ? (string) ($originalAmount ?? $installment) : null;

            if ($type->needsAuthorization()) {
                $asked = $basis === DeductionAuthorizationBasis::Employee;
                $deduction->forceFill([
                    'authorization_basis' => $basis,
                    'awaiting_authorization' => $asked,
                    'authorization' => match ($basis) {
                        DeductionAuthorizationBasis::Employee => null, // their sentence, once they authorize
                        DeductionAuthorizationBasis::Paper => "Signed on paper; recorded by {$actor->name}.",
                        DeductionAuthorizationBasis::Law => 'Government loan (SSS or Pag-IBIG): the employer deducts it as the law requires.',
                        DeductionAuthorizationBasis::LossOrDamage => "Company item lost or damaged through their fault; decided by {$actor->name} after they were heard.",
                    },
                    'authorized_at' => $asked ? null : now(),
                ]);
            }

            $deduction->save();

            $this->audit->record(AuditArea::Payroll, 'deduction.created', "Added {$type->label()} \"{$label}\" for {$employee->name}", $deduction, [
                'new' => ['installment_amount' => $installment, 'original_amount' => $originalAmount, 'authorization' => $type->needsAuthorization() ? $basis->value : null],
            ], causer: $actor);

            if ($deduction->awaiting_authorization) {
                $employee->notify(new DeductionAuthorizationNotice($deduction, DeductionAuthorizationNotice::REQUESTED));
            }

            return $deduction;
        });
    }
}
