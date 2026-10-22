<?php

namespace App\Actions\Training;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\RequestStatus;
use App\Enums\TrainingRequestKind;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Notifications\TrainingRequestNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A guard asks for a training (My Training). Admin (manage-training) is told.
 * One open request per kind, so a second tap doesn't file it twice.
 */
final class FileTrainingRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $guard, TrainingRequestKind $kind, string $reason, ?CarbonImmutable $preferredFrom): TrainingRequest
    {
        $guard->loadMissing('employeeProfile');

        if ($guard->employeeProfile?->hasDeployment() !== true || ! $guard->employeeProfile->employment_status->isActive()) {
            throw ValidationException::withMessages(['kind' => 'Training requests are for guards. Ask HR about office training.']);
        }

        if ($preferredFrom !== null && $preferredFrom->isBefore(today())) {
            throw ValidationException::withMessages(['preferredFrom' => 'Choose today or a later date.']);
        }

        return DB::transaction(function () use ($guard, $kind, $reason, $preferredFrom) {
            $open = TrainingRequest::query()->where('user_id', $guard->id)->where('kind', $kind)
                ->where(fn ($query) => $query->where('status', RequestStatus::Pending)
                    ->orWhere(fn ($waiting) => $waiting->where('status', RequestStatus::Approved)->whereNull('training_id')))
                ->lockForUpdate()->exists();

            if ($open) {
                throw ValidationException::withMessages(['kind' => "You already asked for {$kind->label()}. You'll be told when it's decided."]);
            }

            $request = new TrainingRequest(['kind' => $kind, 'reason' => $reason, 'preferred_from' => $preferredFrom?->toDateString()]);
            $request->user()->associate($guard);
            $request->save();

            $this->audit->record(AuditArea::Training, 'training.requested', "{$guard->name} asked for {$kind->label()}", $request, causer: $guard);

            Notification::send(User::query()->notifiable(Ability::ManageTraining->roles())->whereKeyNot($guard->id)->get(), new TrainingRequestNotice($request, TrainingRequestNotice::FILED));

            return $request;
        });
    }
}
