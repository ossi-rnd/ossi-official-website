<?php

namespace App\Support;

/**
 * Minutes as people read them: 314 → "5 hr 14 min", 60 → "1 hr", 45 → "45 min".
 * Short form for tight cells: "5h 14m".
 */
final class Duration
{
    public static function minutes(int $minutes, bool $short = false): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        $parts = array_filter([
            $hours > 0 ? $hours.($short ? 'h' : ' hr') : null,
            $rest > 0 || $hours === 0 ? $rest.($short ? 'm' : ' min') : null,
        ]);

        return implode(' ', $parts);
    }
}
