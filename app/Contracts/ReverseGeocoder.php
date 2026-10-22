<?php

namespace App\Contracts;

/**
 * Turns coordinates into a street address. An interface so tests can swap in
 * a fake and so the provider (Nominatim today) can change without touching callers.
 */
interface ReverseGeocoder
{
    /**
     * @return string|null the address, or null when the lookup failed
     */
    public function addressFor(float $latitude, float $longitude): ?string;
}
