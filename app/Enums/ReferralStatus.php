<?php

namespace App\Enums;

/**
 * A referral's way through: submitted → hired (bonus waiting for the days to pass) → paid,
 * or not hired, or forfeited (the hire left before the days passed), or withdrawn by the referrer.
 */
enum ReferralStatus: string
{
    case Submitted = 'submitted';
    case Hired = 'hired';
    case Paid = 'paid';
    case NotHired = 'not_hired';
    case Forfeited = 'forfeited';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'With HR',
            self::Hired => 'Hired',
            self::Paid => 'Bonus paid',
            self::NotHired => 'Not hired',
            self::Forfeited => 'No bonus (left early)',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Submitted => 'amber',
            self::Hired, self::Paid => 'blue',
            default => 'gray',
        };
    }
}
