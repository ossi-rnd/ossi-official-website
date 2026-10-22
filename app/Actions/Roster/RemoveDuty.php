<?php

namespace App\Actions\Roster;

use App\Enums\AuditArea;
use App\Models\DutyAssignment;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes a day a day off. Same limits as SaveDuty: not for past days, not
 * once clocked in, and not for a relief duty.
 */
final class RemoveDuty
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(DutyAssignment $duty, User $actor): void
    {
        $duty->loadMissing(['user', 'client']);
        RosterRules::authorize($duty->user, $duty->client, $actor);

        if ($duty->duty_date->lt(today())) {
            throw ValidationException::withMessages(['editShiftId' => 'That day has passed.']);
        }

        if ($duty->isRelief()) {
            throw ValidationException::withMessages(['editShiftId' => 'This is a relief duty. Change the reliever on Relievers.']);
        }

        if ($duty->timeLog()->exists()) {
            throw ValidationException::withMessages(['editShiftId' => "{$duty->user->name} already clocked in for this duty."]);
        }

        DB::transaction(function () use ($duty, $actor) {
            $this->audit->record(AuditArea::Operations, 'duty.removed', "Day off for {$duty->user->name} on {$duty->duty_date->format('D, M j')}", $duty, causer: $actor);
            UpdateNotice::tell($duty->user, $actor, 'Day off: '.$duty->duty_date->format('D, M j'), 'Your duty that day was removed from the roster. Don\'t report unless Operations tells you.', route('attendance'), 'calendar');
            $duty->delete();
        });
    }
}
