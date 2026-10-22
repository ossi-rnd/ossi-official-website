<?php

namespace App\Services\Attendance;

/**
 * The status shown for one person on one day. Shared by the history page and
 * the dashboard so the precedence can't drift between them:
 * a real time log wins, then "not yet hired", holiday, approved leave,
 * "pending" for today, and finally absent.
 */
final class DayStatusResolver
{
    public static function resolve(?string $logStatus, bool $isHoliday, bool $isOnLeave, bool $isToday, bool $isFuture = false, bool $isBeforeHire = false): string
    {
        return match (true) {
            $logStatus !== null => $logStatus,
            $isBeforeHire => 'not_hired',
            $isFuture => 'upcoming',
            $isHoliday => 'holiday',
            $isOnLeave => 'on_leave',
            $isToday => 'pending',
            default => 'absent',
        };
    }
}
