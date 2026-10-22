<?php

namespace App\Enums;

use App\Support\LeaveRules;

/**
 * The leave an employee can file. Besides the company's own (vacation, sick,
 * emergency, other), the leaves Philippine law gives, each with the proof it
 * needs. The day counts are shown, not enforced: the approver sees when a
 * request runs longer, and decides (the laws count days differently).
 */
enum LeaveType: string
{
    case Vacation = 'vacation';
    case Sick = 'sick';
    case Emergency = 'emergency';
    case Other = 'other';

    // Leaves under special laws.
    case Maternity = 'maternity';
    case Paternity = 'paternity';
    case SoloParent = 'solo_parent';
    case Vawc = 'vawc';
    case SpecialWomen = 'special_women';

    public function label(): string
    {
        return match ($this) {
            self::Maternity => 'Maternity leave',
            self::Paternity => 'Paternity leave',
            self::SoloParent => 'Solo parent leave',
            self::Vawc => 'VAWC leave',
            self::SpecialWomen => 'Special leave for women',
            default => ucfirst($this->value),
        };
    }

    /** The law that gives it, for the special leaves. */
    public function law(): ?string
    {
        return match ($this) {
            self::Maternity => 'RA 11210',
            self::Paternity => 'RA 8187',
            self::SoloParent => 'RA 11861',
            self::Vawc => 'RA 9262',
            self::SpecialWomen => 'RA 9710',
            default => null,
        };
    }

    /** What the law gives, in words; null for the company's own leaves. */
    public function entitlement(): ?string
    {
        return match ($this) {
            self::Maternity => '105 days with pay (120 for a solo parent), for each pregnancy',
            self::Paternity => '7 days with pay, for each of the first four deliveries of the legitimate spouse',
            self::SoloParent => '7 working days with pay a year, after 6 months of service',
            self::Vawc => '10 days with pay, for a victim of violence against women and their children',
            self::SpecialWomen => 'Up to 2 months with full pay, after surgery for a gynecological disorder',
            default => null,
        };
    }

    /** The most days the law gives, to flag a longer request to the approver. */
    public function maxDays(): ?int
    {
        return match ($this) {
            self::Maternity => 120,
            self::Paternity, self::SoloParent => 7,
            self::Vawc => 10,
            self::SpecialWomen => 60,
            default => null,
        };
    }

    /**
     * The proof needed before it can be approved, or null. Sick leave needs a
     * medical certificate from LeaveRules::sickCertificateFromDays() days on (HR sets it).
     */
    public function document(int $days): ?string
    {
        return match ($this) {
            self::Sick => $days >= LeaveRules::sickCertificateFromDays() ? 'A medical certificate' : null,
            self::Maternity => 'A medical certificate of the pregnancy, or the child\'s birth certificate',
            self::Paternity => 'The child\'s birth certificate (or proof of the delivery) and your marriage certificate',
            self::SoloParent => 'Your Solo Parent ID',
            self::Vawc => 'A Barangay Protection Order, a court order, or a certificate that a case is filed (from the barangay, prosecutor or court)',
            self::SpecialWomen => 'A medical certificate of the gynecological surgery and the recovery it needs',
            default => null,
        };
    }

    /**
     * Leave the law says is paid (suggested on the payroll run, PaidLeavePayment).
     * Maternity is paid as the SSS maternity benefit, advanced by the employer and
     * reimbursed by SSS: computed from the SSS salary credit, so Accounting enters it.
     */
    public function isPaidByLaw(): bool
    {
        return in_array($this, [self::Maternity, self::Paternity, self::SoloParent, self::Vawc, self::SpecialWomen], true);
    }

    /** The pay is the SSS maternity benefit (Accounting enters the amount), not days × the daily rate. */
    public function paidAsSssBenefit(): bool
    {
        return $this === self::Maternity;
    }

    /**
     * @return list<string>
     */
    public static function paidByLawValues(): array
    {
        return array_values(array_map(fn (self $type) => $type->value, array_filter(self::cases(), fn (self $type) => $type->isPaidByLaw())));
    }

    /** May be filed after it started (you're sick or it's an emergency first, and file once back). */
    public function canStartInThePast(): bool
    {
        return $this === self::Sick || $this === self::Emergency;
    }

    /**
     * Confidential (RA 9262 Sec. 44): it goes straight to whoever decides, skipping
     * the endorsers, and nobody else sees it in their lists.
     */
    public function isConfidential(): bool
    {
        return $this === self::Vawc;
    }

    /**
     * @return list<string>
     */
    public static function confidentialValues(): array
    {
        return array_values(array_map(fn (self $type) => $type->value, array_filter(self::cases(), fn (self $type) => $type->isConfidential())));
    }

    /**
     * The form's two groups.
     *
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        return [
            'Company leave' => [self::Vacation, self::Sick, self::Emergency, self::Other],
            'Leave under special laws' => [self::Maternity, self::Paternity, self::SoloParent, self::Vawc, self::SpecialWomen],
        ];
    }
}
