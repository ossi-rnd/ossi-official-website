<?php

namespace App\Enums;

/** Who sees an item in the equipment shop, and can be issued it. */
enum EquipmentAudience: string
{
    case Guards = 'guards';
    case Office = 'office';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Guards => 'Guards',
            self::Office => 'Office staff',
            self::All => 'Everyone',
        };
    }

    /** Guards see guard items and shared ones; office staff (or anyone without a deployment) office items and shared ones. */
    public function includes(bool $officeStaff): bool
    {
        return $this === self::All || ($officeStaff ? $this === self::Office : $this === self::Guards);
    }
}
