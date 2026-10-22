<?php

namespace App\Actions\Patrols;

use App\Enums\AuditArea;
use App\Models\AwayLocationUpdate;
use App\Models\AwayLog;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;

/**
 * Run nightly. The Data Privacy Act asks that personal data be kept no longer
 * than needed, so the GPS trail of an absence (each shared spot, and where
 * "left" and "back" were tapped) is deleted after
 * attendance.away.location_retention_days. What stays, with the attendance
 * records: the reason, note, times, "said back by", added time, approval and
 * the distance numbers. Street addresses are never stored (looked up live).
 */
final class PruneAwayLocations
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return int how many absences had their trail deleted
     */
    public function handle(CarbonImmutable $now): int
    {
        $cutoff = $now->subDays((int) config('attendance.away.location_retention_days'));
        $pruned = 0;

        AwayLog::query()
            ->whereNull('locations_pruned_at')
            ->whereNotNull('returned_at')
            ->where('left_at', '<', $cutoff)
            ->select('id')
            ->chunkById(500, function ($aways) use ($now, &$pruned) {
                $ids = $aways->pluck('id')->all();

                AwayLocationUpdate::query()->whereIn('away_log_id', $ids)->delete();
                AwayLog::query()->whereKey($ids)->update([
                    'left_latitude' => null,
                    'left_longitude' => null,
                    'returned_latitude' => null,
                    'returned_longitude' => null,
                    'locations_pruned_at' => $now,
                ]);

                $pruned += count($ids);
            });

        if ($pruned > 0) {
            $this->audit->record(AuditArea::Attendance, 'away.locations_pruned', "Deleted the location trail of {$pruned} ".str('absence')->plural($pruned).' older than '.config('attendance.away.location_retention_days').' days (retention policy)');
        }

        return $pruned;
    }
}
