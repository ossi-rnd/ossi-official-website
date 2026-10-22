<?php

namespace App\Enums;

enum DeductionType: string
{
    case Loan = 'loan';
    case Uniform = 'uniform';
    case Equipment = 'equipment';
    case CashAdvance = 'cash_advance';
    // Borrow Load: created only by approving a load request.
    case Load = 'load';
    // Paid in every cut-off (₱100) and kept, not owed: refunded in final pay. See CashBond.
    case CashBond = 'cash_bond';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CashAdvance => 'Cash Advance',
            self::Load => 'Borrowed Load',
            self::CashBond => 'Cash Bond',
            default => ucfirst($this->value),
        };
    }

    /**
     * Balance-tracked types: each generated payslip reduces the remaining balance.
     */
    public function tracksBalance(): bool
    {
        return $this === self::Loan || $this === self::CashAdvance || $this === self::Load;
    }

    /**
     * Deductions Accounting adds by hand need a basis (Art. 113): the employee's written
     * authorization, or the law. Load has its own (the request), and the cash bond is
     * enrolled with the employment.
     */
    public function needsAuthorization(): bool
    {
        return ! in_array($this, [self::Load, self::CashBond], true);
    }

    /**
     * The types Accounting can add by hand on a payslip. Load comes from Load Requests.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $type !== self::Load));
    }
}
