<?php

namespace App\Actions\Patrols;

use App\Models\Client;
use App\Models\PatrolCheckpoint;
use App\Models\PatrolScan;
use App\Models\TimeLog;
use App\Models\User;
use App\Services\Attendance\Geofence;
use App\Services\Attendance\PunchPhotoStore;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A roving guard scans a checkpoint's QR sticker. Proof the guard was
 * physically there, at that time:
 *
 *  - the QR code is random and must belong to the client of the guard's duty;
 *  - the guard must be clocked in;
 *  - the GPS fix must be accurate enough and within reach of the checkpoint
 *    (its own coordinates, or the client's geofence);
 *  - the camera frame that read the QR is kept, so a photo of a photo shows.
 *
 * Scanning the same checkpoint again within a few minutes returns the first
 * scan instead of adding another.
 */
final class RecordPatrolScan
{
    public function __construct(
        private readonly PunchPhotoStore $photos,
        private readonly Geofence $geofence,
    ) {}

    public function handle(User $guard, string $payload, string $photoDataUrl, float $latitude, float $longitude, ?int $accuracyMeters): PatrolScan
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw $this->reject('Your GPS location is missing. Allow location access and try again.');
        }

        if ($accuracyMeters !== null && $accuracyMeters > (int) config('attendance.max_gps_accuracy_meters')) {
            throw $this->reject("Your GPS fix is too rough (±{$accuracyMeters} m). Wait a few seconds, then scan again.");
        }

        $code = str_starts_with($payload, PatrolCheckpoint::QR_PREFIX) ? substr($payload, strlen(PatrolCheckpoint::QR_PREFIX)) : '';
        $checkpoint = $code === '' ? null : PatrolCheckpoint::query()->with('client')->where('code', $code)->active()->first();

        if ($checkpoint === null) {
            throw $this->reject('That isn\'t an OSSI checkpoint QR. Scan the sticker at the checkpoint.');
        }

        $now = CarbonImmutable::now();
        $log = TimeLog::query()->with('dutyAssignment.client')
            ->where('user_id', $guard->id)->whereNotNull('clock_in_at')->whereNull('clock_out_at')
            ->whereDate('log_date', '>=', $now->subDay()->toDateString())
            ->latest('clock_in_at')
            ->first();

        if ($log === null) {
            throw $this->reject('Clock in first: scans count during your duty.');
        }

        $guard->loadMissing('employeeProfile.client');
        $site = $log->dutyAssignment->client ?? $guard->employeeProfile?->client;

        if ($site === null || $site->id !== $checkpoint->client_id) {
            throw $this->reject($site === null
                ? "{$checkpoint->name} is at {$checkpoint->client->name}, and you have no post today."
                : "{$checkpoint->name} is at {$checkpoint->client->name}. Your post today is {$site->name}: scan the stickers there.");
        }

        $distance = $this->ensureInReach($checkpoint, $site, $latitude, $longitude, $accuracyMeters);

        $repeat = PatrolScan::query()
            ->where('time_log_id', $log->id)
            ->where('patrol_checkpoint_id', $checkpoint->id)
            ->where('scanned_at', '>=', $now->subMinutes((int) config('attendance.patrol.repeat_scan_minutes')))
            ->first();

        if ($repeat !== null) {
            return $repeat;
        }

        $photoPath = $this->photos->storeAs($photoDataUrl, sprintf('patrols/%d/%s', $guard->id, $now->toDateString()), 'scan');

        try {
            $scan = new PatrolScan;
            $scan->forceFill([
                'user_id' => $guard->id,
                'patrol_checkpoint_id' => $checkpoint->id,
                'time_log_id' => $log->id,
                'scanned_at' => $now,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy_meters' => $accuracyMeters,
                'distance_meters' => $distance,
                'photo_path' => $photoPath,
            ])->save();

            return $scan->setRelation('checkpoint', $checkpoint);
        } catch (Throwable $exception) {
            $this->photos->delete($photoPath);

            throw $exception;
        }
    }

    /**
     * @return int|null meters from the checkpoint (or the site), when it has coordinates
     */
    private function ensureInReach(PatrolCheckpoint $checkpoint, Client $site, float $latitude, float $longitude, ?int $accuracyMeters): ?int
    {
        [$lat, $lng, $radius] = match (true) {
            $checkpoint->hasLocation() => [(float) $checkpoint->latitude, (float) $checkpoint->longitude, (int) config('attendance.patrol.checkpoint_radius_meters')],
            $site->hasGeofence() => [(float) $site->latitude, (float) $site->longitude, $site->geofence_meters],
            default => [null, null, null],
        };

        if ($lat === null || $lng === null || $radius === null) {
            return null;
        }

        $distance = $this->geofence->distanceMeters($latitude, $longitude, $lat, $lng);

        if (! $this->geofence->isInside($distance, $radius, $accuracyMeters)) {
            throw $this->reject("You're {$this->geofence->describe($distance)} from {$checkpoint->name}. Scan it where the sticker is.");
        }

        return $distance;
    }

    private function reject(string $message): ValidationException
    {
        return ValidationException::withMessages(['scan' => $message]);
    }
}
