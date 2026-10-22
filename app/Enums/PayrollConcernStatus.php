<?php

namespace App\Enums;

/**
 * open → resolved (paid on a payslip, or explained with no change) | declined (not valid).
 */
enum PayrollConcernStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
            self::Declined => 'Not valid',
        };
    }

    /** A tone name; Blade owns the classes. */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'amber',
            self::Resolved => 'blue',
            self::Declined => 'gray',
        };
    }
}
