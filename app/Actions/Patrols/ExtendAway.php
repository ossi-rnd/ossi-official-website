<?php

namespace App\Actions\Patrols;

use App\Enums\AuditArea;
use App\Models\AwayExtension;
use App\Models\AwayLog;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "I need more time": traffic, a long queue, the errand grew. The person adds
 * time to their own absence with a short reason; no approval is needed, and
 * nobody is alerted. It moves the expected return, so a lateness alert only
 * comes if they're well past the new time.
 */
final class ExtendAway
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $person, int $minutes, string $reason): AwayExtension
    {
        $reason = trim($reason);

        if (! in_array($minutes, config('attendance.away.extensions'), true)) {
            throw ValidationException::withMessages(['extendMinutes' => 'Choose how much time to add.']);
        }

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['extendReason' => 'Say why in a few words (e.g. traffic on EDSA).']);
        }

        return DB::transaction(function () use ($person, $minutes, $reason) {
            $away = AwayLog::query()->where('user_id', $person->id)->open()->lockForUpdate()->first();

            if ($away === null) {
                throw ValidationException::withMessages(['extendMinutes' => 'You\'re not marked away.']);
            }

            $now = CarbonImmutable::now();
            $previous = $away->expectedBackAt();
            // Added from now when already late, so "+30 min" really means 30 more minutes.
            $next = ($previous->greaterThan($now) ? $previous : $now)->addMinutes($minutes);

            if ($away->left_at->diffInMinutes($next) > (int) config('attendance.away.max_minutes')) {
                throw ValidationException::withMessages(['extendMinutes' => 'That\'s longer than one absence can be. Tap "Back" when you return, or call your supervisor.']);
            }

            $extension = new AwayExtension;
            $extension->forceFill([
                'away_log_id' => $away->id,
                'minutes' => $minutes,
                'reason' => mb_substr($reason, 0, 200),
                'previous_back_at' => $previous,
                'new_back_at' => $next,
            ])->save();

            $away->forceFill(['expected_back_at' => $next])->save();

            $this->audit->record(AuditArea::Attendance, 'away.extended', "{$person->name} added {$minutes} min ({$reason}); back by {$next->format('g:i A')}", $away, causer: $person);

            return $extension;
        });
    }
}
