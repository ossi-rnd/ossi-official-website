<?php

namespace App\Http\Controllers;

use App\Contracts\ReverseGeocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets the camera screen show "you are at ..." before the punch. Throttled to
 * 20 per user per minute (spec). A failure is a 502 the page can show; it
 * never stops the punch itself.
 */
final class ReverseGeocodeController extends Controller
{
    public function __invoke(Request $request, ReverseGeocoder $geocoder): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $address = $geocoder->addressFor((float) $validated['lat'], (float) $validated['lon']);

        return $address === null
            ? response()->json(['message' => 'Address lookup failed.'], 502)
            : response()->json(['address' => $address]);
    }
}
