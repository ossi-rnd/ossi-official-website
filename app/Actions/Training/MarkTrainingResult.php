<?php

namespace App\Actions\Training;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\TrainingAttendance;
use App\Models\TrainingAttendee;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Completed, did not complete, or back to enrolled. "Completed" is what shows
 * on the guard's employee record.
 */
final class MarkTrainingResult
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(TrainingAttendee $attendee, TrainingAttendance $status, User $actor): void
    {
        if (! $actor->can(Ability::ManageTraining->value)) {
            throw new AuthorizationException;
        }

        $attendee->loadMissing(['training', 'profile']);

        if ($status !== TrainingAttendance::Enrolled && $attendee->training->starts_on->isFuture()) {
            throw ValidationException::withMessages(['attendance' => 'The training hasn\'t started yet.']);
        }

        $before = $attendee->status;

        if ($before === $status) {
            return;
        }

        $attendee->forceFill(['status' => $status])->save();

        $this->audit->record(AuditArea::Training, 'training.marked', "{$attendee->profile->name}: {$status->label()} in \"{$attendee->training->title}\"", $attendee->training, [
            'old' => ['status' => $before->value], 'new' => ['status' => $status->value],
        ], causer: $actor);

        if ($status !== TrainingAttendance::Enrolled) {
            UpdateNotice::tellProfile($attendee->profile, $actor, "{$attendee->training->title}: {$status->label()}",
                $status === TrainingAttendance::Completed ? 'It\'s on your training record now.' : 'Ask Admin about the next session.', route('my-training'), 'academic-cap');
        }
    }
}
