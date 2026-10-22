<?php

namespace App\Actions\Training;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\TrainingAttendee;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RemoveTrainee
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(TrainingAttendee $attendee, User $actor): void
    {
        if (! $actor->can(Ability::ManageTraining->value)) {
            throw new AuthorizationException;
        }

        $attendee->loadMissing(['training', 'profile']);

        DB::transaction(function () use ($attendee, $actor) {
            $this->audit->record(AuditArea::Training, 'training.removed', "Removed {$attendee->profile->name} from \"{$attendee->training->title}\"", $attendee->training, causer: $actor);
            UpdateNotice::tellProfile($attendee->profile, $actor, "Removed from training: {$attendee->training->title}",
                "You're no longer in the session on {$attendee->training->dateLabel()}.", route('my-training'), 'academic-cap');
            $attendee->delete();
        });
    }
}
