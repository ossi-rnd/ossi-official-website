<?php

namespace App\Actions\Attendance;

use App\Actions\Patrols\CloseForgottenAways;
use App\Actions\Relievers\RecordNoShowArrival;
use App\Data\DutyForPunch;
use App\Enums\PunchType;
use App\Enums\TimeLogStatus;
use App\Models\AwayLog;
use App\Models\Client;
use App\Models\Office;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use App\Queries\Attendance\CurrentDutyQuery;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\Geofence;
use App\Services\Attendance\PunchPhotoStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Records one clock-in or clock-out with its selfie and GPS position.
 *
 * Which schedule it counts against: the guard's rostered duty for now
 * (CurrentDutyQuery), or their usual shift when they aren't on the roster.
 * Off duty, too early or after the duty ended, the clock-in is refused.
 *
 * Anti-cheating, checked BEFORE anything is saved:
 *  - a GPS fix rougher than attendance.max_gps_accuracy_meters is refused;
 *  - the punch must be inside the post's geofence (client coordinates + radius),
 *    for clock-out too, so nobody clocks out from home; office staff inside their
 *    OSSI office's (an office staffer with no office set has no geofence);
 *  - the server's clock, never the phone's; the selfie is burned with the time.
 * Clocking in early is allowed but earns nothing: late, night differential
 * and overtime all count from the scheduled start.
 *
 * Concurrency (spec: "two rapid taps produce one punch"):
 *  - the day's time log row is locked with SELECT ... FOR UPDATE, so a second
 *    request waits, then sees the first punch and is rejected;
 *  - for a clock-in there is no row to lock yet, so two racing inserts are
 *    stopped by the unique (user_id, log_date) index instead.
 *
 * The caller says WHICH punch it means. If the server guessed, a double-tapped
 * clock-in would silently become a clock-out.
 *
 * The phone (a cookie code, CheckPunchDevice): a guard's first clock-in registers
 * their phone; a punch from another one still counts, and is flagged to Operations
 * and HR (ReportOtherPhone).
 */
final class RecordPunch
{
    public function __construct(
        private readonly PunchPhotoStore $photos,
        private readonly AttendanceCalculator $calculator,
        private readonly CurrentDutyQuery $currentDuty,
        private readonly Geofence $geofence,
        private readonly RecordNoShowArrival $noShowArrival,
        private readonly CheckPunchDevice $checkDevice,
        private readonly ReportOtherPhone $reportOtherPhone,
    ) {}

    public function handle(
        User $user,
        PunchType $type,
        string $photoDataUrl,
        float $latitude,
        float $longitude,
        ?int $accuracyMeters = null,
        ?string $address = null,
        ?string $deviceToken = null,
        ?string $deviceLabel = null,
    ): TimeLog {
        $this->ensureValidCoordinates($latitude, $longitude);
        $this->ensureAccurateEnough($accuracyMeters);

        $now = CarbonImmutable::now();
        $duty = null;

        if ($type === PunchType::ClockIn) {
            $duty = $this->currentDuty->handle($user, $now);

            if ($duty->refusal !== null) {
                throw $this->reject($duty->refusal.$this->nextDutyHint($duty));
            }

            $this->ensureAtPost($duty->site ?? $this->officeOf($user), $latitude, $longitude, $accuracyMeters, $type);
        } else {
            $this->ensureAtPost($this->clockOutSite($user, $now) ?? $this->officeOf($user), $latitude, $longitude, $accuracyMeters, $type);
        }

        // Stored first, outside the transaction: file writes can't be rolled back, so we delete it on failure instead.
        $photoPath = $this->photos->store($photoDataUrl, $user, $type, $now);

        try {
            [$timeLog, $punch] = DB::transaction(function () use ($user, $type, $now, $duty, $photoPath, $latitude, $longitude, $accuracyMeters, $address, $deviceToken, $deviceLabel) {
                $timeLog = $duty !== null
                    ? $this->clockIn($user, $now, $duty)
                    : $this->clockOut($user, $now);

                $punch = $timeLog->punches()->create([
                    'type' => $type,
                    'punched_at' => $now,
                    'photo_path' => $photoPath,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'accuracy_meters' => $accuracyMeters,
                    // A failed address lookup never blocks the punch (spec rule 6).
                    'address' => $address !== null && trim($address) !== '' ? mb_substr(trim($address), 0, 500) : null,
                    ...$this->checkDevice->handle($user, $deviceToken, $deviceLabel !== null ? mb_substr($deviceLabel, 0, 100) : null, $now),
                ]);

                return [$timeLog, $punch];
            });
        } catch (UniqueConstraintViolationException) {
            $this->photos->delete($photoPath);

            throw $this->reject('You have already clocked in for this duty.');
        } catch (Throwable $e) {
            $this->photos->delete($photoPath);

            throw $e;
        }

        // After the commit: the punch is saved whatever happens to the alert.
        if ($punch->other_device) {
            $this->reportOtherPhone->handle($punch);
        }

        return $timeLog;
    }

    private function clockIn(User $user, CarbonImmutable $now, DutyForPunch $duty): TimeLog
    {
        $existing = TimeLog::query()
            ->where('user_id', $user->id)
            ->whereDate('log_date', $duty->dutyDate->toDateString())
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            throw $this->reject($duty->dutyDate->isToday() ? 'You have already clocked in today.' : 'You have already clocked in for this duty.');
        }

        $shift = $duty->shift;
        $late = $this->calculator->lateMinutes($now, $shift, $duty->dutyDate);

        $timeLog = new TimeLog;
        $timeLog->user()->associate($user);
        $timeLog->shift_id = $shift->exists ? $shift->id : null;
        $timeLog->duty_assignment_id = $duty->duty?->id;
        $timeLog->log_date = $duty->dutyDate;
        $timeLog->clock_in_at = $now;
        $timeLog->late_minutes = $late;
        $timeLog->status = TimeLogStatus::fromMinutes($late, 0, hasClockedOut: false);
        $timeLog->save();

        // Still "away" from an earlier duty (never tapped Back, never clocked out, and the
        // scheduler hasn't closed it yet)? It ended with that duty: close it, flagged, so it
        // doesn't carry into this one and hide the patrol scanner.
        AwayLog::query()->where('user_id', $user->id)->where('time_log_id', '!=', $timeLog->id)->open()->with('timeLog.shift')->get()
            ->each(function (AwayLog $away) use ($now) {
                $dutyEnd = CloseForgottenAways::dutyEnd($away)->min($now);
                $away->forceFill(['returned_at' => $dutyEnd->greaterThan($away->left_at) ? $dutyEnd : $away->left_at, 'auto_closed_at' => $now])->save();
            });

        // Flagged as a no-show, then turned up: Operations stops looking for a reliever.
        $this->noShowArrival->handle($timeLog);

        return $timeLog;
    }

    private function clockOut(User $user, CarbonImmutable $now): TimeLog
    {
        $timeLog = $this->openLog($user, $now, lock: true);

        if ($timeLog === null) {
            $closedToday = TimeLog::query()
                ->where('user_id', $user->id)
                ->whereDate('log_date', $now->toDateString())
                ->whereNotNull('clock_out_at')
                ->exists();

            throw $this->reject($closedToday ? 'You have already clocked out today.' : 'You must clock in before you can clock out.');
        }

        $shift = $timeLog->shift ?? $this->usualShift($user);
        $clockIn = $timeLog->clock_in_at ?? $now;

        $timeLog->clock_out_at = $now;
        $timeLog->undertime_minutes = $this->calculator->undertimeMinutes($now, $shift, $timeLog->log_date);
        $timeLog->overtime_minutes = $this->calculator->overtimeMinutes($clockIn, $now, $shift);
        $timeLog->status = TimeLogStatus::fromMinutes($timeLog->late_minutes, $timeLog->undertime_minutes, hasClockedOut: true);
        $timeLog->save();

        // Still marked away? The clock-out passed the geofence, so they're back at the post.
        AwayLog::query()->where('time_log_id', $timeLog->id)->open()->update(['returned_at' => $now]);

        return $timeLog;
    }

    /**
     * Today's open log, or yesterday's if that shift crosses midnight (a
     * 22:00–07:00 guard clocks out the next morning). A day-shift log left
     * open yesterday is NOT closed today; it stays incomplete.
     */
    private function openLog(User $user, CarbonImmutable $now, bool $lock): ?TimeLog
    {
        $candidates = TimeLog::query()
            ->with(['shift', 'dutyAssignment.client'])
            ->where('user_id', $user->id)
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->whereDate('log_date', '>=', $now->subDay()->toDateString())
            ->whereDate('log_date', '<=', $now->toDateString())
            ->orderByDesc('log_date')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();

        return $candidates->first(function (TimeLog $log) use ($now): bool {
            if ($log->log_date->isSameDay($now)) {
                return true;
            }

            return ($log->shift ?? Shift::fallback())->crossesMidnight();
        });
    }

    /**
     * Where a clock-out must happen: the post of the duty being closed, else
     * the guard's usual client.
     */
    private function clockOutSite(User $user, CarbonImmutable $now): ?Client
    {
        $open = $this->openLog($user, $now, lock: false);
        $user->loadMissing('employeeProfile.client');

        return $open->dutyAssignment->client ?? $user->employeeProfile?->client;
    }

    private function usualShift(User $user): Shift
    {
        $user->loadMissing('employeeProfile.shift');

        return $user->employeeProfile->shift ?? Shift::fallback();
    }

    /** An office staffer's OSSI office (their clock-in place); null for guards or when none is set. */
    private function officeOf(User $user): ?Office
    {
        $user->loadMissing('employeeProfile.office');

        return $user->employeeProfile?->is_office_staff ? $user->employeeProfile->office : null;
    }

    private function ensureAtPost(Client|Office|null $site, float $latitude, float $longitude, ?int $accuracyMeters, PunchType $type): void
    {
        if ($site === null || ! $site->hasGeofence()) {
            return;
        }

        $distance = $this->geofence->distanceMeters($latitude, $longitude, (float) $site->latitude, (float) $site->longitude);

        if (! $this->geofence->isInside($distance, $site->geofence_meters, $accuracyMeters)) {
            $verb = $type === PunchType::ClockIn ? 'Clock in' : 'Clock out';
            $where = $site instanceof Office ? 'at the office' : 'at your post';

            throw ValidationException::withMessages(['location' => "You're {$this->geofence->describe($distance)} from {$site->name}. {$verb} {$where}."]);
        }
    }

    private function ensureAccurateEnough(?int $accuracyMeters): void
    {
        $limit = (int) config('attendance.max_gps_accuracy_meters');

        if ($accuracyMeters !== null && $accuracyMeters > $limit) {
            throw ValidationException::withMessages(['location' => "Your GPS fix is too rough (±{$accuracyMeters} m). Step into an open spot or wait a few seconds, then try again."]);
        }
    }

    private function nextDutyHint(DutyForPunch $duty): string
    {
        if ($duty->next === null) {
            return '';
        }

        [$start] = $this->calculator->shiftWindow($duty->next->shift, $duty->next->duty_date);

        return " Your next duty: {$start->format('D, M j \a\t g:i A')}, {$duty->next->placeLabel()}.";
    }

    private function ensureValidCoordinates(float $latitude, float $longitude): void
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw ValidationException::withMessages(['location' => 'Your GPS location is missing or invalid. Allow location access and try again.']);
        }
    }

    private function reject(string $message): ValidationException
    {
        return ValidationException::withMessages(['punch' => $message]);
    }
}
