<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a guard: their client (detachment), post and shift. Nothing else on
 * the record. Operations does this without HR's full edit access; a
 * Satellite Operation only for guards in their region, and only to a client
 * in their region.
 */
final class DeployEmployee
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(EmployeeProfile $profile, ?Client $client, ?string $post, ?Shift $shift, User $actor): EmployeeProfile
    {
        if (! $actor->can(Ability::DeployGuards->value) || ! $actor->canSeeProfile($profile)) {
            throw new AuthorizationException;
        }

        if (! $profile->hasDeployment()) {
            throw ValidationException::withMessages(['deployClientId' => 'Office staff are not deployed to a client.']);
        }

        if ($client !== null && $actor->isRegionallyLimited() && $client->region !== $actor->operations_region) {
            throw ValidationException::withMessages(['deployClientId' => 'Choose a client in your region.']);
        }

        return DB::transaction(function () use ($profile, $client, $post, $shift, $actor) {
            $fields = ['client_id', 'detachment', 'post', 'shift_id'];
            $before = $profile->only($fields);

            $profile->forceFill([
                'client_id' => $client?->id,
                'detachment' => $client?->name,
                'post' => filled($post) ? trim($post) : null,
            ]);
            $profile->shift()->associate($shift);
            $profile->save();

            $changes = AuditLogger::diff($before, $profile->only($fields));

            if ($changes['new'] !== []) {
                $this->audit->record(AuditArea::People, 'profile.deployed', "Deployed {$profile->name} to ".($client !== null ? "{$client->name} ({$client->locationLabel()})" : 'no client'), $profile, $changes, causer: $actor);
                // The guard reports somewhere new: they hear it from the app, not only by word of mouth.
                UpdateNotice::tellProfile($profile, $actor, 'Your deployment changed', $profile->assignmentLabel().($shift !== null ? ' · '.$shift->label() : '').'.', route('attendance'), 'map-pin', email: true);
            }

            return $profile;
        });
    }
}
