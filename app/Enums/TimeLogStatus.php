<?php

namespace App\Enums;

enum TimeLogStatus: string
{
    case OnTime = 'on_time';
    case Late = 'late';
    case Undertime = 'undertime';
    case LateAndUndertime = 'late_and_undertime';
    case Incomplete = 'incomplete';

    /**
     * Spec rule 5, in priority order.
     */
    public static function fromMinutes(int $lateMinutes, int $undertimeMinutes, bool $hasClockedOut): self
    {
        return match (true) {
            $lateMinutes > 0 && $undertimeMinutes > 0 => self::LateAndUndertime,
            $lateMinutes > 0 => self::Late,
            $undertimeMinutes > 0 => self::Undertime,
            ! $hasClockedOut => self::Incomplete,
            default => self::OnTime,
        };
    }

    public function isLate(): bool
    {
        return $this === self::Late || $this === self::LateAndUndertime;
    }
}
