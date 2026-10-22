<?php

namespace App\Queries\Attendance;

use App\Actions\Patrols\UpdateAwayLocation;
use App\Contracts\ReverseGeocoder;
use App\Enums\AwayReason;
use App\Models\AwayExtension;
use App\Models\AwayLocationUpdate;
use App\Models\AwayLog;
use App\Services\Attendance\Geofence;
use Carbon\CarbonImmutable;

/**
 * Everything the "where is this guard" window shows for one absence: the
 * post and its geofence, every point of the trail (left → updates → back),
 * and the street address of the latest spot.
 *
 * Only the latest spot is looked up (cached by the geocoder): OpenStreetMap
 * asks for at most one lookup a second.
 *
 * @phpstan-type TrailPoint array{lat: float|null, lng: float|null, accuracy: int|null, label: string, kind: string, at: CarbonImmutable, distance: int|null, mapUrl: string|null}
 */
final class AwayTrailQuery
{
    public function __construct(
        private readonly ReverseGeocoder $geocoder,
        private readonly Geofence $geofence,
    ) {}

    /**
     * @return array{post: array{lat: float, lng: float, radius: int, name: string}|null, points: list<TrailPoint>, address: string|null, latest: TrailPoint}
     */
    public function handle(AwayLog $away): array
    {
        $away->loadMissing(['locationUpdates', 'extensions', 'timeLog.dutyAssignment.client', 'user.employeeProfile.client']);
        // A guard's post, or the head office for office staff.
        $post = UpdateAwayLocation::postFor($away, $away->user);

        $distance = fn (?float $lat, ?float $lng): ?int => $post !== null && $lat !== null && $lng !== null
            ? $this->geofence->distanceMeters($lat, $lng, $post['lat'], $post['lng'])
            : null;
        $map = fn (?float $lat, ?float $lng): ?string => $lat !== null && $lng !== null ? "https://www.google.com/maps?q={$lat},{$lng}" : null;

        $leftLat = $away->left_latitude !== null ? (float) $away->left_latitude : null;
        $leftLng = $away->left_longitude !== null ? (float) $away->left_longitude : null;

        $points = [[
            'lat' => $leftLat, 'lng' => $leftLng, 'accuracy' => null, 'kind' => 'left', 'at' => $away->left_at,
            'label' => 'Left '.$away->left_at->format('g:i A'), 'distance' => $distance($leftLat, $leftLng), 'mapUrl' => $map($leftLat, $leftLng),
        ]];

        foreach ($away->locationUpdates->sortBy('recorded_at') as $update) {
            /** @var AwayLocationUpdate $update */
            $lat = (float) $update->latitude;
            $lng = (float) $update->longitude;
            $points[] = [
                'lat' => $lat, 'lng' => $lng, 'accuracy' => $update->accuracy_meters, 'kind' => 'update', 'at' => $update->recorded_at,
                'label' => $update->recorded_at->format('g:i A'), 'distance' => $update->distance_meters ?? $distance($lat, $lng), 'mapUrl' => $map($lat, $lng),
            ];
        }

        // "Needed more time" has no position: the timeline shows it, the map skips it.
        foreach ($away->extensions as $extension) {
            /** @var AwayExtension $extension */
            $points[] = [
                'lat' => null, 'lng' => null, 'accuracy' => null, 'kind' => 'extended', 'at' => $extension->created_at,
                'label' => '+'.AwayReason::durationLabel($extension->minutes).': '.$extension->reason.' (back by '.$extension->new_back_at->format('g:i A').')', 'distance' => null, 'mapUrl' => null,
            ];
        }

        usort($points, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        if ($away->returned_at !== null) {
            $lat = $away->returned_latitude !== null ? (float) $away->returned_latitude : null;
            $lng = $away->returned_longitude !== null ? (float) $away->returned_longitude : null;
            $points[] = [
                'lat' => $lat, 'lng' => $lng, 'accuracy' => null, 'kind' => 'returned', 'at' => $away->returned_at,
                'label' => 'Back '.$away->returned_at->format('g:i A'), 'distance' => $away->returned_distance_meters ?? $distance($lat, $lng), 'mapUrl' => $map($lat, $lng),
            ];
        }

        // The latest point that has coordinates (an update can't lack them; leaving or returning can).
        $latest = collect($points)->last(fn (array $point) => $point['lat'] !== null) ?? $points[array_key_last($points)];

        $address = $latest['lat'] !== null && $latest['lng'] !== null ? $this->geocoder->addressFor($latest['lat'], $latest['lng']) : null;

        return ['post' => $post, 'points' => $points, 'address' => $address, 'latest' => $latest];
    }
}
