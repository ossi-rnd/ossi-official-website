<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\PositionChange;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Takes back a promotion or change of position that hasn't started yet (its day
 * is still ahead). One that started is history: record a new change instead.
 */
final class CancelPositionChange
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, PositionChange $change): void
    {
        if (! $actor->can(Ability::ManagePeople->value)) {
            throw new AuthorizationException;
        }

        if ($change->applied_at !== null) {
            throw ValidationException::withMessages(['change' => 'It already started. Record a new change of position instead.']);
        }

        $change->loadMissing('profile');
        $this->audit->record(AuditArea::People, 'position.cancelled', "Cancelled the {$change->kindLabel()} of {$change->profile->name} to {$change->to_position->label()} (was from {$change->effective_on->format('M j, Y')})", $change->profile, causer: $actor);
        // They were told of it when it was set (ChangePosition): tell them it's off.
        UpdateNotice::tellProfile($change->profile, $actor, "Change of position cancelled: {$change->to_position->label()}",
            "It was to start on {$change->effective_on->format('M j, Y')}. Your position stays as it is.", route('profile'), 'user');
        $change->delete();
    }
}
