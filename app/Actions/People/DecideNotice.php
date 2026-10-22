<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\NteOutcome;
use App\Models\MeritRecord;
use App\Models\MeritType;
use App\Models\NoticeToExplain;
use App\Models\User;
use App\Notifications\NoticeToExplainNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The second notice: the decision and its reasons, only once due process is
 * done (explained, or the days to explain have passed). Cleared, a written
 * reprimand, a suspension (days and from when), or dismissal. It can also give a
 * demerit from HR's list.
 *
 * Nothing is changed automatically beyond that: HR sets "Terminated" on the
 * employee record for a dismissal (and is told), and a suspension simply has no
 * attendance to pay. The employee is told.
 */
final class DecideNotice
{
    public function __construct(
        private readonly GiveMerit $giveMerit,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $actor, NoticeToExplain $notice, NteOutcome $decision, string $reason, ?int $suspensionDays = null, ?CarbonImmutable $suspensionFrom = null, ?MeritType $demerit = null): NoticeToExplain
    {
        if (! NoticeToExplain::canIssue($actor, $notice->user)) {
            throw new AuthorizationException;
        }

        $suspending = $decision === NteOutcome::Suspension;
        $errors = array_filter([
            'decision' => match (true) {
                $notice->isDecided() => 'It was already decided.',
                ! $notice->readyToDecide() => 'Wait for the explanation, or until '.$notice->explain_by->format('M j').': the employee must have their chance to explain first.',
                default => null,
            },
            'decisionReason' => mb_strlen(trim($reason)) < 20 ? 'Give the reasons: the facts found and why this decision (a few sentences).' : null,
            'suspensionDays' => $suspending && ($suspensionDays === null || $suspensionDays < 1 || $suspensionDays > 30) ? 'From 1 to 30 days.' : null,
            'suspensionFrom' => $suspending && $suspensionFrom === null ? 'From when?' : null,
            'demeritTypeId' => $demerit !== null && $demerit->kind !== MeritType::DEMERIT ? 'Choose a demerit.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($actor, $notice, $decision, $reason, $suspending, $suspensionDays, $suspensionFrom, $demerit) {
            $locked = NoticeToExplain::query()->with('user')->lockForUpdate()->findOrFail($notice->id);

            // Dated the incident, unless that's older than merits go back (then today).
            $today = CarbonImmutable::today();
            $record = $demerit !== null && $decision !== NteOutcome::Cleared && MeritRecord::canGive($actor, $locked->user)
                ? $this->giveMerit->handle($actor, $locked->user, $demerit, "Notice to Explain: {$locked->subject}. ".trim($reason), $locked->incident_date->isBefore($today->subDays(90)) ? $today : $locked->incident_date)
                : null;

            $locked->forceFill([
                'status' => NoticeToExplain::DECIDED,
                'decision' => $decision,
                'decision_reason' => trim($reason),
                'suspension_days' => $suspending ? $suspensionDays : null,
                'suspension_from' => $suspending ? $suspensionFrom?->toDateString() : null,
                'merit_record_id' => $record?->id,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditArea::People, 'nte.decided', "Decision on {$locked->user->name}'s Notice to Explain ({$locked->subject}): {$decision->label()}", $locked, [
                'decision' => $decision->value,
                'suspension_days' => $locked->suspension_days,
                'reason' => $locked->decision_reason,
            ], causer: $actor);

            $locked->user->notify(new NoticeToExplainNotice($locked, NoticeToExplainNotice::DECIDED));

            // A dismissal: HR updates the employee record (status, final pay, clearance).
            if ($decision === NteOutcome::Dismissal) {
                $hr = User::query()->notifiable(Ability::ManagePeople->roles())->whereKeyNot($actor->id)->get();
                Notification::send($hr, new NoticeToExplainNotice($locked, NoticeToExplainNotice::DISMISSAL_HR));
            }

            return $locked;
        });
    }
}
