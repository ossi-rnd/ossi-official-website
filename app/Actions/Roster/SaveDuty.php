<?php

namespace App\Actions\Roster;

use App\Enums\AuditArea;
use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets (or changes) one guard's duty for one day: shift template, client and
 * post. Operations only; a Satellite Operation within its region.
 *
 * Refused for past days and for a duty already clocked in: attendance that
 * happened can't be rescheduled after the fact. Relief duties are managed on
 * Relievers, where they come from.
 */
final class SaveDuty
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $guard, CarbonImmutable $date, Shift $shift, ?Client $client, ?string $post, User $actor, bool $patrols = false): DutyAssignment
    {
        RosterRules::authorize($guard, $client, $actor);

        if ($date->lt(today())) {
            throw ValidationException::withMessages(['editShiftId' => 'That day has passed. Record attendance by hand instead if the guard worked.']);
        }

        return DB::transaction(function () use ($guard, $date, $shift, $client, $post, $actor, $patrols) {
            $duty = DutyAssignment::query()->where('user_id', $guard->id)->whereDate('duty_date', $date->toDateString())->lockForUpdate()->first();

            if ($duty?->isRelief()) {
                throw ValidationException::withMessages(['editShiftId' => 'This is a relief duty. Change the reliever on Relievers.']);
            }

            if (TimeLog::query()->where('user_id', $guard->id)->whereDate('log_date', $date->toDateString())->exists()) {
                throw ValidationException::withMessages(['editShiftId' => "{$guard->name} already clocked in for this day."]);
            }

            $before = $duty?->only(['shift_id', 'client_id', 'post', 'patrols']) ?? [];
            $duty ??= new DutyAssignment;

            $duty->forceFill([
                'user_id' => $guard->id,
                'duty_date' => $date->toDateString(),
                'shift_id' => $shift->id,
                'client_id' => $client?->id,
                'post' => filled($post) ? trim((string) $post) : null,
                'patrols' => $patrols,
                'assigned_by' => $actor->id,
            ])->save();

            $changes = AuditLogger::diff($before, $duty->only(['shift_id', 'client_id', 'post', 'patrols']));
            $this->audit->record(AuditArea::Operations, 'duty.set', "Duty for {$guard->name} on {$date->format('D, M j')}: {$shift->name} at ".($client->name ?? 'no client'), $duty, $changes, causer: $actor);

            if ($changes['new'] !== []) {
                UpdateNotice::tell($guard, $actor, ($before === [] ? 'Duty set: ' : 'Duty changed: ').$date->format('D, M j'),
                    $shift->label().' at '.collect([$client?->name, $duty->post])->filter()->implode(' · ').($patrols ? ' (patrol rounds)' : '').'.', route('attendance'), 'calendar');
            }

            return $duty;
        });
    }
}
