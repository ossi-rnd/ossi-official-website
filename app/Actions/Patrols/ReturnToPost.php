<?php

namespace App\Actions\Patrols;

use App\Enums\AuditArea;
use App\Models\AwayLog;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Back at post" ends the absence. It isn't blocked by the geofence (an
 * errand can end anywhere, GPS can be off indoors): where it was tapped, and
 * how far that is from the post, is recorded, and Operations sees a return
 * far from the post on Patrols.
 */
final class ReturnToPost
{
    public function __construct(
        private readonly UpdateAwayLocation $locations,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $guard, ?float $latitude, ?float $longitude, ?int $accuracyMeters): AwayLog
    {
        return DB::transaction(function () use ($guard, $latitude, $longitude) {
            $away = AwayLog::query()->with('timeLog.dutyAssignment.client')->where('user_id', $guard->id)->open()->lockForUpdate()->first();

            if ($away === null) {
                throw ValidationException::withMessages(['return' => 'You\'re not marked away.']);
            }

            $distance = $latitude !== null && $longitude !== null ? $this->locations->distanceFromPost($away, $guard, $latitude, $longitude) : null;

            $away->forceFill([
                'returned_at' => CarbonImmutable::now(),
                'returned_latitude' => $latitude,
                'returned_longitude' => $longitude,
                'returned_distance_meters' => $distance,
            ])->save();

            $this->audit->record(AuditArea::Attendance, 'away.returned', "{$guard->name} back after {$away->minutesAway(CarbonImmutable::now())} min ({$away->reason->label()})".($distance !== null && $distance > 500 ? ', tapped '.round($distance / 1000, 1).' km from the post' : ''), $away, causer: $guard);

            return $away;
        });
    }
}
