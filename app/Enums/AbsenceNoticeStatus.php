<?php

namespace App\Enums;

/**
 * open → covered (a reliever is assigned) — or cancelled by the guard while
 * still open. Reassigning a reliever keeps it covered.
 *
 * A no-show still open when the guard clocks in after all becomes arrived:
 * nobody needs sending any more.
 *
 * Still open once its day has passed: not covered (CloseUncoveredAbsences, daily):
 * the post went without a guard. Kept, not deleted: it's what a client asks about.
 */
enum AbsenceNoticeStatus: string
{
    case Open = 'open';
    case Covered = 'covered';
    case Cancelled = 'cancelled';
    case Arrived = 'arrived';
    case NotCovered = 'not_covered';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Needs reliever',
            self::Covered => 'Reliever assigned',
            self::Cancelled => 'Cancelled',
            self::Arrived => 'Arrived late',
            self::NotCovered => 'Not covered',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'amber',
            self::Covered => 'blue',
            self::Cancelled, self::Arrived => 'gray',
            // The post went without a guard that day.
            self::NotCovered => 'red',
        };
    }
}
