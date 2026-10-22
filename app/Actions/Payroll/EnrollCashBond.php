<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Enums\DeductionType;
use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Starts someone's cash bond: a "Cash Bond" deduction of ₱100 a cut-off
 * (₱200 a month), with no balance to pay off: it builds up until it's
 * refunded in final pay. Idempotent: an active one is left as it is.
 *
 * Guards are enrolled when they register their account (ClaimEmployeeAccount);
 * those already working with `app:enroll-cash-bonds` or the Cash Bonds page.
 */
final class EnrollCashBond
{
    /** ₱100 a cut-off, the company's rule (₱200 a month). */
    public const float PER_CUTOFF = 100.0;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return bool true when it was started now, false when already running
     */
    public function handle(User $user, ?User $actor = null, float $perCutoff = self::PER_CUTOFF): bool
    {
        if (EmployeeDeduction::query()->active()->where('user_id', $user->id)->where('type', DeductionType::CashBond)->exists()) {
            return false;
        }

        $deduction = new EmployeeDeduction;
        $deduction->forceFill([
            'user_id' => $user->id,
            'label' => 'Cash bond',
            'type' => DeductionType::CashBond,
            'installment_amount' => number_format($perCutoff, 2, '.', ''),
            'original_amount' => null,
            'remaining_balance' => null,
            'is_active' => true,
        ])->save();

        $this->audit->record(AuditArea::Payroll, 'cash_bond.enrolled', "Started {$user->name}'s cash bond (₱".number_format($perCutoff, 2).' a cut-off)', $deduction, causer: $actor);

        return true;
    }
}
