<?php

namespace App\Enums;

/**
 * Where a pending leave request is waiting.
 *
 * A guard's leave climbs: the region's Satellite Operation endorses to
 * Operation HQ, Operation HQ endorses to HR, and HR decides (final). With no
 * Satellite account for the guard's region it starts at Operation HQ.
 * Office staff's leave goes straight to the President or Vice President.
 */
enum LeaveStage: string
{
    case Satellite = 'satellite';
    case OperationsHq = 'hq';
    case Hr = 'hr';
    case Executive = 'executive';

    public function label(): string
    {
        return match ($this) {
            self::Satellite => 'Satellite Operation',
            self::OperationsHq => 'Operation HQ',
            self::Hr => 'HR',
            self::Executive => 'President or VP',
        };
    }

    /** An endorsing step (it moves up), not a deciding one. */
    public function endorses(): bool
    {
        return in_array($this, [self::Satellite, self::OperationsHq], true);
    }
}
