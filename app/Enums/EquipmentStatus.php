<?php

namespace App\Enums;

/** Where one issued (or asked-for) item stands. */
enum EquipmentStatus: string
{
    case Requested = 'requested';
    case Rejected = 'rejected';
    case Issued = 'issued';
    case Returned = 'returned';
    // Reported lost and decided: off their hands, whether or not they were charged.
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Rejected => 'Not approved',
            self::Issued => 'With them',
            self::Returned => 'Returned',
            self::Lost => 'Lost',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            // Colour only where someone should act.
            self::Requested => 'amber',
            self::Issued => 'blue',
            default => 'gray',
        };
    }
}
