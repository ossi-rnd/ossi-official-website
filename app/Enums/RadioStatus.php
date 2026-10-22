<?php

namespace App\Enums;

/** Where a radio stands. Only one in use has a post or a guard. */
enum RadioStatus: string
{
    case InUse = 'in_use';
    case Spare = 'spare';
    case ForRepair = 'for_repair';
    case Lost = 'lost';
    // Gone from the agency: sold, scrapped or returned. Kept on the list as the record.
    case Disposed = 'disposed';

    public function label(): string
    {
        return match ($this) {
            self::InUse => 'In use',
            self::Spare => 'Spare (in stock)',
            self::ForRepair => 'For repair',
            self::Lost => 'Lost',
            self::Disposed => 'Disposed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            // Colour only where someone should act.
            self::ForRepair, self::Lost => 'amber',
            default => 'gray',
        };
    }

    /** Still the agency's to keep licensed: everything but disposed. A lost radio's permit still matters until it's reported. */
    public function needsPermit(): bool
    {
        return $this !== self::Disposed;
    }
}
