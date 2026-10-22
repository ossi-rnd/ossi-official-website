<?php

namespace App\Enums;

/** A petty cash replenishment request: waiting for Accounting, then released (cash given back) or rejected. */
enum ReplenishmentStatus: string
{
    case Pending = 'pending';
    case Released = 'released';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for Accounting',
            self::Released => 'Released',
            self::Rejected => 'Returned',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Released => 'blue',
            self::Rejected => 'gray',
        };
    }
}
