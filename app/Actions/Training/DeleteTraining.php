<?php

namespace App\Actions\Training;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\TrainingAttendance;
use App\Models\Training;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * For a session entered by mistake or called off. Once someone completed it,
 * it's part of their training record and stays.
 */
final class DeleteTraining
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Training $training, User $actor): void
    {
        if (! $actor->can(Ability::ManageTraining->value)) {
            throw new AuthorizationException;
        }

        if ($training->attendees()->where('status', TrainingAttendance::Completed)->exists()) {
            throw ValidationException::withMessages(['training' => 'Guards completed this training, so it stays on their records.']);
        }

        DB::transaction(function () use ($training, $actor) {
            $this->audit->record(AuditArea::Training, 'training.deleted', "Deleted training \"{$training->title}\" ({$training->dateLabel()})", $training, causer: $actor);

            // Called off: those enrolled shouldn't travel to it.
            if ($training->starts_on->endOfDay()->isFuture()) {
                foreach ($training->attendees()->with('profile.user')->get() as $attendee) {
                    UpdateNotice::tellProfile($attendee->profile, $actor, "Training called off: {$training->title}",
                        "The session on {$training->dateLabel()} won't happen. Admin will tell you if it's moved.", route('my-training'), 'academic-cap', email: true);
                }
            }

            $training->delete();
        });
    }
}
