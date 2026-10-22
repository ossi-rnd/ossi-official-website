<?php

namespace App\Actions\Patrols;

use App\Enums\AuditArea;
use App\Enums\AwayReason;
use App\Models\AwayLog;
use App\Models\TimeLog;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Someone on duty says they're stepping away, why, and for how long (the
 * reason's usual time unless they pick another). A guard leaving the post is
 * watched by Operations; office staff stepping out of the office by the
 * Executive (like their leave). They can add time while away (ExtendAway).
 */
final class LeavePost
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  int|null  $minutes  how long they expect to be; null = the reason's usual time
     */
    public function handle(User $guard, AwayReason $reason, ?string $note, ?float $latitude, ?float $longitude, ?int $minutes = null): AwayLog
    {
        $guard->loadMissing('employeeProfile');
        $isOffice = $guard->employeeProfile->is_office_staff ?? true;

        if (! in_array($reason, AwayReason::for($isOffice), true)) {
            throw ValidationException::withMessages(['awayReason' => 'Choose one of the reasons listed.']);
        }

        if ($minutes !== null && ! in_array($minutes, config('attendance.away.durations'), true)) {
            throw ValidationException::withMessages(['awayMinutes' => 'Choose one of the lengths listed.']);
        }

        $minutes ??= $reason->usualMinutes();

        if ($reason->needsNote() && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages(['awayNote' => 'Say where you\'re going (a few words).']);
        }

        return DB::transaction(function () use ($guard, $reason, $note, $latitude, $longitude, $minutes) {
            // Locked on the user: two taps can't open two absences.
            User::query()->whereKey($guard->id)->lockForUpdate()->first();
            $now = CarbonImmutable::now();

            $log = TimeLog::query()->where('user_id', $guard->id)->whereNotNull('clock_in_at')->whereNull('clock_out_at')
                ->whereDate('log_date', '>=', $now->subDay()->toDateString())->latest('clock_in_at')->first();

            if ($log === null) {
                throw ValidationException::withMessages(['awayReason' => 'You\'re not clocked in.']);
            }

            if (AwayLog::query()->where('user_id', $guard->id)->open()->exists()) {
                throw ValidationException::withMessages(['awayReason' => 'You\'re already marked away. Tap "Back at post" first.']);
            }

            $away = new AwayLog;
            $away->forceFill([
                'user_id' => $guard->id,
                'time_log_id' => $log->id,
                'reason' => $reason,
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
                'left_at' => $now,
                'left_latitude' => $latitude,
                'left_longitude' => $longitude,
                'expected_back_at' => $now->addMinutes($minutes),
            ])->save();

            $this->audit->record(AuditArea::Attendance, 'away.left', "{$guard->name} left the post: {$reason->label()}, back by {$now->addMinutes($minutes)->format('g:i A')}", $away, causer: $guard);

            return $away;
        });
    }
}
