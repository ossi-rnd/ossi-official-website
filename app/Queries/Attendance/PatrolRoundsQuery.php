<?php

namespace App\Queries\Attendance;

use App\Models\PatrolCheckpoint;
use App\Models\PatrolScan;
use App\Models\TimeLog;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\Geofence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The patrol rounds of one roving duty: the duty is cut into windows of the
 * client's patrol interval (06:00–08:00, 08:00–10:00…), and each round is
 * complete when every active checkpoint was scanned inside its window.
 *
 * Rounds that end before the guard clocked in don't count against them.
 *
 * A round can also look faked (`suspicious`, a reason in words): its scans
 * came faster than anyone walks between checkpoints, or all from one spot
 * while the pinned checkpoints are metres apart. That's the guard scanning
 * photos of the stickers from the guardhouse.
 *
 * @phpstan-type Round array{index: int, from: CarbonImmutable, to: CarbonImmutable, state: string, scanned: list<int>, missing: list<string>, suspicious: string|null}
 */
final class PatrolRoundsQuery
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly Geofence $geofence,
    ) {}

    /**
     * @return array{rounds: list<Round>, checkpoints: Collection<int, PatrolCheckpoint>}
     */
    public function handle(TimeLog $log, CarbonImmutable $now): array
    {
        $log->loadMissing(['dutyAssignment.shift', 'dutyAssignment.client']);
        $duty = $log->dutyAssignment;
        $client = $duty?->client;

        if ($duty === null || ! $duty->patrols || $client === null || $log->clock_in_at === null) {
            return ['rounds' => [], 'checkpoints' => new Collection];
        }

        $checkpoints = PatrolCheckpoint::query()->where('client_id', $client->id)->active()->orderBy('sort_order')->orderBy('name')->get();

        if ($checkpoints->isEmpty()) {
            return ['rounds' => [], 'checkpoints' => $checkpoints];
        }

        $scans = PatrolScan::query()->where('time_log_id', $log->id)->orderBy('scanned_at')->get(['patrol_checkpoint_id', 'scanned_at', 'latitude', 'longitude']);
        [$start, $end] = $this->calculator->shiftWindow($duty->shift, $duty->duty_date);
        $interval = max(15, $client->patrol_interval_minutes);
        $clockedOut = $log->clock_out_at;

        $rounds = [];
        $index = 0;

        for ($from = $start; $from->lt($end); $from = $from->addMinutes($interval)) {
            $to = $from->addMinutes($interval)->min($end);

            if ($to->lte($log->clock_in_at)) {
                continue;
            }

            $index++;
            $inRound = $scans->filter(fn (PatrolScan $scan) => $scan->scanned_at->gte($from) && $scan->scanned_at->lt($to))->values();
            $scanned = $inRound->pluck('patrol_checkpoint_id')->unique()->values()->all();
            $missing = $checkpoints->reject(fn (PatrolCheckpoint $checkpoint) => in_array($checkpoint->id, $scanned, true))->pluck('name')->values()->all();
            $over = $now->gte($to) || ($clockedOut !== null && $clockedOut->lt($to));

            $rounds[] = [
                'index' => $index,
                'from' => $from,
                'to' => $to,
                'state' => match (true) {
                    $missing === [] => 'done',
                    $over => 'missed',
                    $now->gte($from) => 'current',
                    default => 'upcoming',
                },
                'scanned' => array_values(array_map('intval', $scanned)),
                'missing' => array_values(array_map('strval', $missing)),
                'suspicious' => $this->suspicious($inRound, $checkpoints),
            ];
        }

        return ['rounds' => $rounds, 'checkpoints' => $checkpoints];
    }

    /**
     * Why a round's scans don't look like someone walking the route, or null.
     *
     * @param  Collection<int, PatrolScan>  $scans  the round's scans, in time order
     * @param  Collection<int, PatrolCheckpoint>  $checkpoints
     */
    private function suspicious(Collection $scans, Collection $checkpoints): ?string
    {
        // One scan per checkpoint (the first), and at least three of them to judge a route.
        $route = $scans->unique('patrol_checkpoint_id')->values();
        $first = $route->first();
        $last = $route->last();

        if ($route->count() < 3 || $first === null || $last === null) {
            return null;
        }

        $seconds = (int) $first->scanned_at->diffInSeconds($last->scanned_at);
        $perCheckpoint = (int) config('attendance.patrol.suspicious_seconds_per_checkpoint');

        if ($seconds < ($route->count() - 1) * $perCheckpoint) {
            return "{$route->count()} checkpoints scanned in {$seconds} seconds";
        }

        // All scanned from one spot, though the checkpoints themselves are spread out.
        $pinned = $checkpoints->filter(fn (PatrolCheckpoint $checkpoint) => $checkpoint->hasLocation() && in_array($checkpoint->id, $route->pluck('patrol_checkpoint_id')->all(), true))->values();

        if ($pinned->count() >= 2
            && $this->spread($pinned->map(fn (PatrolCheckpoint $checkpoint) => [(float) $checkpoint->latitude, (float) $checkpoint->longitude])) >= (int) config('attendance.patrol.suspicious_spread_meters')
            && $this->spread($route->map(fn (PatrolScan $scan) => [(float) $scan->latitude, (float) $scan->longitude])) <= (int) config('attendance.patrol.suspicious_same_spot_meters')) {
            return "all {$route->count()} checkpoints scanned from one spot";
        }

        return null;
    }

    /**
     * The largest distance between any two of these points, in metres.
     *
     * @param  Collection<int, array{0: float, 1: float}>  $points
     */
    private function spread(Collection $points): int
    {
        $widest = 0;
        $all = $points->values()->all();

        foreach ($all as $i => [$lat, $lng]) {
            foreach (array_slice($all, $i + 1) as [$otherLat, $otherLng]) {
                $widest = max($widest, $this->geofence->distanceMeters($lat, $lng, $otherLat, $otherLng));
            }
        }

        return $widest;
    }
}
