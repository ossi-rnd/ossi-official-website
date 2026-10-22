<?php

namespace App\Actions\FinalPay;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\FinalPayStatus;
use App\Models\FinalPay;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes Accounting's adjustment lines on a draft (a refund, a damaged-equipment
 * charge…), each with a reason and who added it, then recomputes the statement.
 * Add one ($label, $amount, $reason) or remove one ($removeIndex).
 */
final class UpdateFinalPayAdjustments
{
    public function __construct(
        private readonly PrepareFinalPay $prepare,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(FinalPay $finalPay, User $actor, ?string $label = null, float $amount = 0.0, ?string $reason = null, ?int $removeIndex = null): FinalPay
    {
        if (! $actor->can(Ability::PrepareFinalPay->value)) {
            throw new AuthorizationException;
        }

        $adding = $removeIndex === null;

        if ($adding && (round($amount, 2) === 0.0 || blank($label) || blank($reason))) {
            throw ValidationException::withMessages(['adjustmentAmount' => 'An adjustment needs a label, an amount (+ adds, − takes off) and a reason.']);
        }

        return DB::transaction(function () use ($finalPay, $actor, $adding, $label, $amount, $reason, $removeIndex) {
            $locked = FinalPay::query()->with('resignation.user')->lockForUpdate()->findOrFail($finalPay->id);

            if ($locked->status !== FinalPayStatus::Draft) {
                throw ValidationException::withMessages(['adjustmentAmount' => 'It has been submitted for signatures. Send it back to change it.']);
            }

            $adjustments = $locked->adjustments ?? [];

            if ($adding) {
                $adjustments[] = ['label' => trim((string) $label), 'amount' => round($amount, 2), 'reason' => trim((string) $reason), 'by' => $actor->name];
                $description = 'Added "'.trim((string) $label).'" (₱'.number_format($amount, 2).')';
            } else {
                unset($adjustments[$removeIndex]);
                $adjustments = array_values($adjustments);
                $description = 'Removed an adjustment';
            }

            $locked->forceFill(['adjustments' => $adjustments])->save();
            $this->audit->record(AuditArea::Payroll, 'final_pay.adjusted', "{$description} on {$locked->resignation->user->name}'s final pay", $locked, causer: $actor);

            return $this->prepare->handle($locked->resignation, $actor);
        });
    }
}
