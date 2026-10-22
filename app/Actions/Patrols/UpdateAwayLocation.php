<?php

namespace App\Actions\Patrols;

use App\Models\AwayLocationUpdate;
use App\Models\AwayLog;
use App\Models\User;
use App\Services\Attendance\Geofence;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A guard away from the post shares where they are. Asked for every
 * attendance.away.location_update_minutes; Operations sees the latest spot,
 * how far it is from the post, and a map link, and is alerted when an update
 * is overdue.
 *
 * Informational, never blocking: a rough fix is saved with its accuracy so
 * Operations can judge it.
 */
final class UpdateAwayLocation
{
    public function __construct(private readonly Geofence $geofence) {}

    public function handle(User $guard, float $latitude, float $longitude, ?int $accuracyMeters): AwayLocationUpdate
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw ValidationException::withMessages(['location' => 'Your GPS location is missing. Allow location access and try again.']);
        }

        $away = AwayLog::query()->with('timeLog.dutyAssignment.client')->where('user_id', $guard->id)->open()->latest('left_at')->first();

        if ($away === null) {
            throw ValidationException::withMessages(['location' => 'You\'re not marked away.']);
        }

        $update = new AwayLocationUpdate;
        $update->forceFill([
            'away_log_id' => $away->id,
            'recorded_at' => CarbonImmutable::now(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_meters' => $accuracyMeters,
            'distance_meters' => $this->distanceFromPost($away, $guard, $latitude, $longitude),
        ])->save();

        return $update;
    }

    /**
     * Meters from where they work, when that place has coordinates. Shared
     * with ReturnToPost.
     */
    public function distanceFromPost(AwayLog $away, User $guard, float $latitude, float $longitude): ?int
    {
        $post = self::postFor($away, $guard);

        return $post !== null ? $this->geofence->distanceMeters($latitude, $longitude, $post['lat'], $post['lng']) : null;
    }

    /**
     * Where this person works: a guard's post (the duty's client, else their
     * usual one), or for office staff their OSSI office, else the head office
     * set in attendance.office. Null when that place has no coordinates.
     *
     * @return array{lat: float, lng: float, radius: int, name: string}|null
     */
    public static function postFor(AwayLog $away, User $person): ?array
    {
        $person->loadMissing(['employeeProfile.client', 'employeeProfile.office']);

        $office = $person->employeeProfile?->is_office_staff ? $person->employeeProfile->office : null;

        if ($office !== null && $office->hasGeofence()) {
            return ['lat' => (float) $office->latitude, 'lng' => (float) $office->longitude, 'radius' => $office->geofence_meters, 'name' => $office->name];
        }

        if ($person->employeeProfile->is_office_staff ?? true) {
            /** @var array{name: string, latitude: float|null, longitude: float|null, radius_meters: int} $office */
            $office = config('attendance.office');

            return $office['latitude'] !== null && $office['longitude'] !== null
                ? ['lat' => $office['latitude'], 'lng' => $office['longitude'], 'radius' => $office['radius_meters'], 'name' => $office['name']]
                : null;
        }

        $away->loadMissing('timeLog.dutyAssignment.client');
        $site = $away->timeLog->dutyAssignment->client ?? $person->employeeProfile?->client;

        return $site !== null && $site->hasGeofence()
            ? ['lat' => (float) $site->latitude, 'lng' => (float) $site->longitude, 'radius' => $site->geofence_meters, 'name' => $site->name]
            : null;
    }
}
