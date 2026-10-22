<?php

namespace App\Services\Geocoding;

use App\Contracts\ReverseGeocoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenStreetMap's free reverse geocoder. Its usage policy asks for at most one
 * request per second and an identifying User-Agent, so results are cached by
 * coordinates rounded to ~11 m: a guard standing at the same post all shift
 * costs one lookup, not one every few seconds.
 *
 * Failure returns null instead of throwing. Spec: a failed lookup must never
 * block a punch.
 */
final class NominatimReverseGeocoder implements ReverseGeocoder
{
    public function addressFor(float $latitude, float $longitude): ?string
    {
        $key = sprintf('geocode:%.4f:%.4f', $latitude, $longitude);

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $address = $this->lookup($latitude, $longitude);

        if ($address !== null) {
            Cache::put($key, $address, now()->addDay());
        }

        return $address;
    }

    private function lookup(float $latitude, float $longitude): ?string
    {
        $contact = config('services.nominatim.contact_email') ?: config('app.url');

        try {
            $response = Http::timeout((int) config('services.nominatim.timeout_seconds'))
                ->withHeaders(['User-Agent' => config('app.name').' attendance ('.$contact.')'])
                ->get((string) config('services.nominatim.url'), [
                    'format' => 'jsonv2',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'zoom' => 18,
                ]);
        } catch (Throwable $e) {
            Log::warning('Reverse geocoding failed', ['error' => $e->getMessage()]);

            return null;
        }

        $name = $response->successful() ? $response->json('display_name') : null;

        return is_string($name) && $name !== '' ? mb_substr($name, 0, 500) : null;
    }
}
