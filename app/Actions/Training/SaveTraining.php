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

final class SaveTraining
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{title: string, provider: string|null, starts_on: string, ends_on: string|null, venue: string|null, valid_until: string|null, notes: string|null}  $data
     */
    public function handle(?Training $training, array $data, User $actor): Training
    {
        if (! $actor->can(Ability::ManageTraining->value)) {
            throw new AuthorizationException;
        }

        if ($data['ends_on'] !== null && $data['ends_on'] < $data['starts_on']) {
            throw ValidationException::withMessages(['endsOn' => 'The last day can\'t be before the first.']);
        }

        return DB::transaction(function () use ($training, $data, $actor) {
            $isNew = $training === null;
            $training ??= new Training;
            $before = $isNew ? [] : $training->only(array_keys($data));

            $training->fill($data);
            $training->created_by ??= $actor->id;
            $training->save();

            $changes = AuditLogger::diff($before, $training->only(array_keys($data)));
            $this->audit->record(AuditArea::Training, $isNew ? 'training.created' : 'training.updated', "Saved training \"{$training->title}\" ({$training->dateLabel()})", $training, $changes, causer: $actor);

            // A session moved or relocated: everyone enrolled must know before they travel.
            if (! $isNew && array_intersect(array_keys($changes['new']), ['starts_on', 'ends_on', 'venue']) !== []) {
                foreach ($training->attendees()->where('status', TrainingAttendance::Enrolled)->with('profile.user')->get() as $attendee) {
                    UpdateNotice::tellProfile($attendee->profile, $actor, "Training moved: {$training->title}",
                        "Now {$training->dateLabel()}".($training->venue ? " at {$training->venue}" : '').'.', route('my-training'), 'academic-cap', email: true);
                }
            }

            return $training;
        });
    }
}
