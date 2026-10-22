<?php

namespace Tests\Fakes;

use App\Contracts\ReverseGeocoder;

/**
 * Why the geocoder is behind an interface: tests swap in this fake and never call OpenStreetMap.
 */
final class FakeReverseGeocoder implements ReverseGeocoder
{
    public bool $fails = false;

    public int $calls = 0;

    public function addressFor(float $latitude, float $longitude): ?string
    {
        $this->calls++;

        return $this->fails ? null : 'Ortigas Center, Pasig City';
    }
}
