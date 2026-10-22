<?php

namespace App\Actions\Training;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\RequestStatus;
use App\Models\EmployeeProfile;
use App\Models\Training;
use App\Models\TrainingAttendee;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Notifications\TrainingRequestNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a guard to a training. Once per session (the unique index backs this up).
 * A training request of theirs approved and waiting for a session is linked to it.
 */
final class EnrollTrainee
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Training $training, EmployeeProfile $profile, User $actor): TrainingAttendee
    {
        if (! $actor->can(Ability::ManageTraining->value)) {
            throw new AuthorizationException;
        }

        if (! $profile->employment_status->isActive()) {
            throw ValidationException::withMessages(['enrollProfileId' => "{$profile->name} no longer works here."]);
        }

        if ($training->attendees()->where('employee_profile_id', $profile->id)->exists()) {
            throw ValidationException::withMessages(['enrollProfileId' => "{$profile->name} is already in this training."]);
        }

        return DB::transaction(function () use ($training, $profile, $actor) {
            $attendee = new TrainingAttendee;
            $attendee->forceFill(['training_id' => $training->id, 'employee_profile_id' => $profile->id])->save();

            $this->audit->record(AuditArea::Training, 'training.enrolled', "Enrolled {$profile->name} in \"{$training->title}\"", $training, causer: $actor);

            // Their request approved "waiting for a session" is answered by this one: link it, and tell them.
            $waiting = TrainingRequest::query()->where('user_id', $profile->user_id)->where('status', RequestStatus::Approved)->whereNull('training_id')->with('user')->get();
            foreach ($waiting as $request) {
                $request->forceFill(['training_id' => $training->id])->save();
                $request->user->notify(new TrainingRequestNotice($request->setRelation('training', $training), TrainingRequestNotice::ADDED_TO_SESSION));
            }

            return $attendee;
        });
    }
}
