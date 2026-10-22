<?php

namespace App\Enums;

/**
 * The clearance checklist before someone leaves. Each is ticked by HR (or the
 * Executive, for HR staff), with who and when. Final pay and the Certificate
 * of Employment follow DOLE Labor Advisory 06-20: final pay within 30 days of
 * separation, the COE within 3 days of the request.
 */
enum ClearanceItem: string
{
    case IdAndUniform = 'id_and_uniform';
    case FirearmAndEquipment = 'firearm_and_equipment';
    case CompanyProperty = 'company_property';
    case LoansSettled = 'loans_settled';
    case Turnover = 'turnover';
    case FinalPay = 'final_pay';
    case Certificate = 'certificate';

    public function label(): string
    {
        return match ($this) {
            self::IdAndUniform => 'Company ID and uniform returned',
            self::FirearmAndEquipment => 'Firearm, radio and post equipment returned',
            self::CompanyProperty => 'Other company property returned',
            self::LoansSettled => 'Loans and load settled',
            self::Turnover => 'Post or duties handed over',
            self::FinalPay => 'Final pay released',
            self::Certificate => 'Certificate of Employment issued',
        };
    }

    /** What the company owes the person (final pay, the certificate), as against what they return. */
    public function fromCompany(): bool
    {
        return $this === self::FinalPay || $this === self::Certificate;
    }

    /**
     * The items for this person: a firearm and post equipment only for guards.
     *
     * @return list<self>
     */
    public static function for(bool $isGuard): array
    {
        return array_values(array_filter(self::cases(), fn (self $item) => $isGuard || $item !== self::FirearmAndEquipment));
    }
}
