<?php

namespace App\Services\Attendance;

/**
 * Pure distance math for the post geofence: no queries, no config reads.
 */
final class Geofence
{
    private const EARTH_RADIUS_METERS = 6_371_000;

    /**
     * Great-circle (haversine) distance in whole meters.
     */
    public function distanceMeters(float $fromLat, float $fromLng, float $toLat, float $toLng): int
    {
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return (int) round(self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * Inside the radius, allowing for the phone's stated GPS error: a fix
     * "±30 m" that lands 170 m out on a 150 m fence could really be inside.
     * The allowance is capped, so a vague fix can't stretch the fence.
     */
    public function isInside(int $distanceMeters, int $radiusMeters, ?int $accuracyMeters): bool
    {
        return $distanceMeters <= $radiusMeters + min(max(0, $accuracyMeters ?? 0), 50);
    }

    /** "850 m" or "2.3 km" */
    public function describe(int $meters): string
    {
        return $meters < 1000 ? "{$meters} m" : number_format($meters / 1000, 1).' km';
    }
}
