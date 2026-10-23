<?php

namespace App\Actions\Equipment;

use App\Actions\Payroll\SaveEmployeeDeduction;
use App\Enums\AuditArea;
use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Enums\EquipmentIncidentDecision;
use App\Enums\EquipmentIncidentKind;
use App\Enums\EquipmentStatus;
use App\Models\EquipmentIncident;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Their head decides a damage or loss report, only once due process is done (they explained,
 * or the days passed). DOLE's conditions for deducting a loss or damage, each enforced:
 *  - the employee is shown responsible: the decision says why;
 *  - they had a fair chance to explain: readyToDecide();
 *  - no more than the actual loss: the amount, and how it was arrived at (a quote, the price);
 *  - at most 20% of a week's wages per payslip: EquipmentIncident::maxInstallmentFor().
 * Charged, it becomes a deduction ("Loss or damage, after due process") with that balance.
 * A lost item leaves their hands either way.
 */
final class DecideEquipmentIncident
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SaveEmployeeDeduction $saveDeduction,
    ) {}

    public function handle(User $decider, EquipmentIncident $incident, bool $charge, string $reason, ?float $amount = null, ?string $amountBasis = null, ?float $installment = null): EquipmentIncident
    {
        $incident->loadMissing('issue.user');
        $employee = $incident->issue->user;

        if (! EquipmentIncident::canDecide($decider, $employee)) {
            throw new AuthorizationException;
        }

        if (! $incident->readyToDecide()) {
            throw ValidationException::withMessages(['decision' => $incident->decision !== null
                ? 'This was already decided.'
                : 'They can still explain until '.$incident->explain_by->format('M j, Y').'. Decide after that, or once they have explained.']);
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['decisionReason' => $charge ? 'Say why it was their fault.' : 'Say why they are not charged.']);
        }

        if ($charge) {
            $this->checkCharge($employee, $amount, $amountBasis, $installment);
        }

        return DB::transaction(function () use ($decider, $incident, $employee, $charge, $reason, $amount, $amountBasis, $installment) {
            $locked = EquipmentIncident::query()->with('issue')->lockForUpdate()->findOrFail($incident->id);

            if ($locked->decision !== null) {
                throw ValidationException::withMessages(['decision' => 'This was already decided.']);
            }

            $deductionId = null;

            if ($charge) {
                $deduction = $this->saveDeduction->handle($employee, "{$locked->kind->label()}: {$locked->issue->describe()}", DeductionType::Equipment, (float) $installment, (float) $amount, $decider, DeductionAuthorizationBasis::LossOrDamage);
                $deduction->forceFill(['remaining_balance' => number_format((float) $amount, 2, '.', '')])->save();
                $deductionId = $deduction->id;
            }

            $locked->forceFill([
                'decision' => $charge ? EquipmentIncidentDecision::Charged : EquipmentIncidentDecision::NotCharged,
                'decision_reason' => mb_substr($reason, 0, 2000),
                'loss_amount' => $charge ? number_format((float) $amount, 2, '.', '') : null,
                'amount_basis' => $charge ? mb_substr(trim((string) $amountBasis), 0, 300) : null,
                'installment' => $charge ? number_format((float) $installment, 2, '.', '') : null,
                'employee_deduction_id' => $deductionId,
                'decided_by' => $decider->id,
                'decided_at' => now(),
            ])->save();

            if ($locked->kind === EquipmentIncidentKind::Lost) {
                $locked->issue->forceFill(['status' => EquipmentStatus::Lost])->save();
            }

            $this->audit->record(AuditArea::Operations, 'equipment.incident_decided', ($charge ? 'Charged' : 'Did not charge')." {$employee->name} for {$locked->issue->describe()} ({$locked->kind->value})", $locked, [
                'new' => ['decision' => $locked->decision?->value, 'amount' => $charge ? $amount : null, 'installment' => $charge ? $installment : null, 'reason' => $reason],
            ], causer: $decider);

            UpdateNotice::tell($employee, $decider, $charge ? "Charged: {$locked->issue->describe()}" : "Not charged: {$locked->issue->describe()}",
                $charge
                    ? '₱'.number_format((float) $amount, 2).', ₱'.number_format((float) $installment, 2)." per payslip.\nWhy: {$reason}"
                    : "Why: {$reason}",
                route('my-equipment'), 'package', email: true);

            return $locked;
        });
    }

    private function checkCharge(User $employee, ?float $amount, ?string $amountBasis, ?float $installment): void
    {
        if ($amount === null || $amount <= 0 || $amount > 999999) {
            throw ValidationException::withMessages(['lossAmount' => 'Enter the actual loss: the repair cost, or what replacing it costs.']);
        }

        if (mb_strlen(trim((string) $amountBasis)) < 5) {
            throw ValidationException::withMessages(['amountBasis' => 'Say how you got the amount (a repair quote, the purchase price…). It may not be more than the actual loss.']);
        }

        if ($installment === null || $installment <= 0 || $installment > $amount) {
            throw ValidationException::withMessages(['installment' => 'Per payslip: more than ₱0 and at most the amount.']);
        }

        $cap = EquipmentIncident::maxInstallmentFor($employee);

        if ($cap === null) {
            throw ValidationException::withMessages(['installment' => 'Set their daily rate first: the law caps this at 20% of a week\'s wages per payslip.']);
        }

        if ($installment > $cap) {
            throw ValidationException::withMessages(['installment' => 'At most ₱'.number_format($cap, 2).' per payslip: 20% of a week\'s wages (DOLE).']);
        }
    }
}
