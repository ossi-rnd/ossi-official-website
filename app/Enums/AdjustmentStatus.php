<?php

namespace App\Enums;

/**
 * Only APPROVED adjustments reach a payslip. Large ones wait for a second
 * person (see config payroll.adjustments.approval_threshold).
 */
enum AdjustmentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'red',
        };
    }
}
