<?php

namespace App\Actions\Training;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\RequestStatus;
use App\Models\Training;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Notifications\TrainingRequestNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin answers a training request: approve (adding the guard to a session, or
 * "waiting for a session" when none is planned yet) or decline with a reason.
 * The guard is told either way.
 */
final class DecideTrainingRequest
{
    public function __construct(private readonly AuditLogger $audit, private readonly EnrollTrainee $enroll) {}

    public function handle(User $admin, TrainingRequest $request, RequestStatus $decision, ?Training $session, ?string $note): TrainingRequest
    {
        if (! $admin->can(Ability::ManageTraining->value)) {
            throw new AuthorizationException;
        }

        if (! in_array($decision, [RequestStatus::Approved, RequestStatus::Rejected], true)) {
            throw ValidationException::withMessages(['review' => 'A training request is approved or declined.']);
        }

        if ($decision === RequestStatus::Rejected && blank($note)) {
            throw ValidationException::withMessages(['reviewNote' => 'Say why, so the guard knows.']);
        }

        if ($session !== null && ($session->ends_on ?? $session->starts_on)->isBefore(today())) {
            throw ValidationException::withMessages(['sessionId' => 'That session is over. Choose an upcoming one, or approve and add them later.']);
        }

        return DB::transaction(function () use ($admin, $request, $decision, $session, $note) {
            $locked = TrainingRequest::query()->with('user.employeeProfile')->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== RequestStatus::Pending) {
                throw ValidationException::withMessages(['review' => 'This request has already been answered.']);
            }

            $profile = $locked->user->employeeProfile;
            $alreadyIn = $session !== null && $profile !== null && $session->attendees()->where('employee_profile_id', $profile->id)->exists();

            if ($decision === RequestStatus::Approved && $session !== null && $profile !== null && ! $alreadyIn) {
                $this->enroll->handle($session, $profile, $admin);
            }

            $locked->forceFill([
                'status' => $decision,
                'training_id' => $decision === RequestStatus::Approved ? $session?->id : null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => filled($note) ? $note : null,
            ])->save();

            $this->audit->record(AuditArea::Training, 'training.request_'.$decision->value, ($decision === RequestStatus::Approved ? 'Approved' : 'Declined')." {$locked->user->name}'s request for {$locked->kind->label()}".($session !== null && $decision === RequestStatus::Approved ? " (added to \"{$session->title}\")" : ''), $locked, causer: $admin);

            $locked->user->notify(new TrainingRequestNotice($locked->setRelation('training', $decision === RequestStatus::Approved ? $session : null), TrainingRequestNotice::DECIDED));

            return $locked;
        });
    }
}
