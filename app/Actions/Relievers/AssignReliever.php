<?php

namespace App\Actions\Relievers;

use App\Enums\Ability;
use App\Enums\AbsenceNoticeStatus;
use App\Enums\AuditArea;
use App\Models\AbsenceNotice;
use App\Models\DutyAssignment;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use App\Notifications\RelieverAssigned;
use App\Queries\Operations\ReliefCandidatesQuery;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a reliever to the absent guard's post. Assigning again replaces the
 * reliever (someone fell through), until the day has passed.
 *
 * The reliever must be on the candidate list (ReliefCandidatesQuery) when
 * this runs, so two people can't both be sent to cover the same day twice.
 *
 * The reliever gets a relief DUTY on the roster for that day: the absent
 * guard's duty (or usual shift) at their client and post. That's what lets
 * the reliever clock in there, inside that post's geofence. Changing the
 * reliever moves the duty, unless the first one already clocked in.
 */
final class AssignReliever
{
    public function __construct(
        private readonly ReliefCandidatesQuery $candidates,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AbsenceNotice $notice, User $reliever, ?string $note, User $actor): AbsenceNotice
    {
        if (! $actor->can(Ability::ManageRelievers->value)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($notice, $reliever, $note, $actor) {
            $locked = AbsenceNotice::query()->with(['user', 'client'])->lockForUpdate()->findOrFail($notice->id);

            if (! $actor->canSeeEmployee($locked->user)) {
                throw new AuthorizationException;
            }

            if ($locked->status === AbsenceNoticeStatus::Cancelled) {
                throw ValidationException::withMessages(['relieverId' => 'The guard cancelled this notice: they\'re reporting after all.']);
            }

            if ($locked->duty_date->lt(today())) {
                throw ValidationException::withMessages(['relieverId' => 'This day has passed.']);
            }

            if (! $this->candidates->handle($locked, $actor)->contains('id', $reliever->id)) {
                throw ValidationException::withMessages(['relieverId' => "{$reliever->name} can't relieve that day: on leave, absent, already relieving someone, or outside your region."]);
            }

            $previous = $locked->reliever_id;
            $this->moveReliefDuty($locked, $reliever, $actor);

            $locked->forceFill([
                'status' => AbsenceNoticeStatus::Covered,
                'reliever_id' => $reliever->id,
                'covered_by' => $actor->id,
                'covered_at' => now(),
                'cover_note' => filled($note) ? trim($note) : null,
            ])->save();

            $this->audit->record(AuditArea::Operations, 'absence.covered', "{$reliever->name} relieves {$locked->user->name} at {$locked->placeLabel()} on {$locked->duty_date->format('M j, Y')}", $locked, [
                'old' => ['reliever_id' => $previous],
                'new' => ['reliever_id' => $reliever->id],
            ], causer: $actor);

            $locked->setRelation('reliever', $reliever);
            $reliever->notify(new RelieverAssigned($locked, forReliever: true));
            $locked->user->notify(new RelieverAssigned($locked, forReliever: false));

            return $locked;
        });
    }

    private function moveReliefDuty(AbsenceNotice $notice, User $reliever, User $actor): void
    {
        $existing = DutyAssignment::query()->where('absence_notice_id', $notice->id)->lockForUpdate()->first();

        if ($existing !== null && $existing->user_id !== $reliever->id) {
            if (TimeLog::query()->where('duty_assignment_id', $existing->id)->exists()) {
                throw ValidationException::withMessages(['relieverId' => 'The current reliever already clocked in for this duty.']);
            }

            $existing->delete();
            $existing = null;
        }

        $absentDuty = DutyAssignment::query()->where('user_id', $notice->user_id)->whereDate('duty_date', $notice->duty_date->toDateString())->first();
        $notice->user->loadMissing('employeeProfile');
        $fallback = Shift::fallback();
        $shiftId = $absentDuty->shift_id ?? $notice->user->employeeProfile->shift_id ?? ($fallback->exists ? $fallback->id : null);

        // No shift saved anywhere (a bare install before ShiftSeeder): nothing to roster.
        if ($shiftId === null) {
            return;
        }

        ($existing ?? new DutyAssignment)->forceFill([
            'user_id' => $reliever->id,
            'duty_date' => $notice->duty_date->toDateString(),
            'shift_id' => $shiftId,
            'client_id' => $notice->client_id,
            'post' => $notice->post,
            'absence_notice_id' => $notice->id,
            'assigned_by' => $actor->id,
        ])->save();
    }
}
