<?php

namespace App\Enums;

/**
 * Load on credit:
 *
 *   pending ──approve (Admin sent the load)──▶ approved   → deducted on the next payslip
 *      ├──reject (reason)──▶ rejected
 *      └──cancel (employee)──▶ cancelled
 *
 * transitionsTo() is the single list of allowed moves; Actions ask it
 * instead of comparing statuses themselves.
 */
enum LoadRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'blue',
            self::Rejected => 'red',
            self::Cancelled => 'gray',
        };
    }

    /**
     * @return list<self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved, self::Rejected, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->transitionsTo(), true);
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Pending];
    }
}
