<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The heading over a day's shifts in the attendance lists: "Today · Wed, Oct 22",
 * "Yesterday · Tue, Oct 21", then "Mon, Oct 20, 2026". Shared by the Attendance Log
 * and the employee's own Punch Log so both read the same.
 */
final class DayHeading
{
    public static function for(CarbonImmutable $date): string
    {
        return match (true) {
            $date->isToday() => 'Today · '.$date->format('D, M j'),
            $date->isYesterday() => 'Yesterday · '.$date->format('D, M j'),
            default => $date->format('D, M j, Y'),
        };
    }
}
