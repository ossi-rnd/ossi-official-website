<?php

namespace App\Enums;

/**
 * pending → approved (→ paid on the next payslip) | rejected | cancelled (by the employee, while pending).
 */
enum ReimbursementStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved · next payslip',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /** A tone name; Blade owns the classes. */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved, self::Paid => 'blue',
            self::Rejected, self::Cancelled => 'gray',
        };
    }
}
