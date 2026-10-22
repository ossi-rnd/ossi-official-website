<?php

namespace App\Support;

/**
 * Puts a phone's GPS accuracy ("±95 m") into words a supervisor can act on.
 * The number means the person (guard or office staff) is somewhere within
 * that distance of the dot, so the wording names nobody's job.
 */
final class GpsAccuracy
{
    /**
     * @return array{label: string, tone: string, detail: string}|null null when the phone didn't say
     */
    public static function describe(?int $meters): ?array
    {
        if ($meters === null) {
            return null;
        }

        $detail = "The phone placed them within about {$meters} m of this spot.";

        return match (true) {
            $meters <= 20 => ['label' => 'Precise location', 'tone' => 'blue', 'detail' => $detail],
            $meters <= 50 => ['label' => 'Good location', 'tone' => 'gray', 'detail' => $detail],
            $meters <= 100 => ['label' => "Rough location, within ~{$meters} m", 'tone' => 'amber', 'detail' => $detail.' Treat it as the general area.'],
            default => ['label' => "Weak signal, may be off by {$meters} m", 'tone' => 'red', 'detail' => $detail.' Indoors or a poor signal: don\'t rely on it.'],
        };
    }
}
