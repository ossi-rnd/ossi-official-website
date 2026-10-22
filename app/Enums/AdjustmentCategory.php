<?php

namespace App\Enums;

/**
 * Why a payslip is adjusted. The category decides the direction and whether
 * the amount counts as taxable pay, so Accounting never has to know the tax
 * rule by heart.
 */
enum AdjustmentCategory: string
{
    case Correction = 'correction';
    case Allowance = 'allowance';
    case Refund = 'refund';
    case Penalty = 'penalty';
    case Reimbursement = 'reimbursement';
    // Leave the law pays (paternity, solo parent, VAWC, special leave for women): from PaidLeavePayment.
    case PaidLeave = 'paid_leave';
    // The bonus for referring someone who was hired and stayed (Referral, PayDueReferralBonuses).
    case ReferralBonus = 'referral_bonus';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PaidLeave => 'Paid leave',
            self::ReferralBonus => 'Referral bonus',
            default => ucfirst($this->value),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Correction => 'Fixes pay that was wrong (e.g. a missed clock-in). Counts as taxable pay.',
            self::Allowance => 'Extra non-taxable pay (e.g. transport, meal).',
            self::Refund => 'Pays back something deducted by mistake.',
            self::Penalty => 'A deduction the employee agreed to in writing (Labor Code Art. 113).',
            self::Reimbursement => 'Pays back company expenses an employee paid for (with the receipt). Not taxable: it isn\'t pay.',
            self::PaidLeave => 'Days of leave the law pays (paternity, solo parent, VAWC, special leave for women), at the daily rate. Taxable: it\'s pay.',
            self::ReferralBonus => 'The bonus for referring someone who was hired and stayed. Not taxed here: a bonus within the ₱90,000 a year of 13th month and other benefits (NIRC Sec. 32(B)(7)(e)).',
            self::Other => 'Anything else; explain it in the reason.',
        };
    }

    /**
     * Corrections replace pay the employee should have earned, so they are
     * taxed like it. Allowances and refunds are not pay for work.
     */
    public function isTaxable(): bool
    {
        return $this === self::Correction || $this === self::PaidLeave;
    }

    public function allowsAddition(): bool
    {
        return $this !== self::Penalty;
    }

    public function allowsDeduction(): bool
    {
        return in_array($this, [self::Correction, self::Penalty, self::Other], true);
    }
}
