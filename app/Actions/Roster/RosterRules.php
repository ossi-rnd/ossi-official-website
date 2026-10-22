<?php

namespace App\Actions\Roster;

use App\Enums\Ability;
use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The checks every roster write shares: the actor manages the roster, can
 * see this guard (a Satellite: its region), the guard is active field staff,
 * and the client is in the actor's region.
 *
 * `handle()` so it fits the Actions convention; the Actions call authorize().
 */
final class RosterRules
{
    public function handle(User $guard, ?Client $client, User $actor): void
    {
        self::authorize($guard, $client, $actor);
    }

    public static function authorize(User $guard, ?Client $client, User $actor): void
    {
        if (! $actor->can(Ability::ManageRoster->value) || ! $actor->canSeeEmployee($guard)) {
            throw new AuthorizationException;
        }

        $guard->loadMissing('employeeProfile');
        $profile = $guard->employeeProfile;

        if ($profile === null || ! $profile->hasDeployment() || ! $profile->employment_status->isActive()) {
            throw ValidationException::withMessages(['editShiftId' => 'Only active guards are rostered.']);
        }

        if ($client !== null && $actor->isRegionallyLimited() && $client->region !== $actor->operations_region) {
            throw ValidationException::withMessages(['editClientId' => 'Choose a client in your region.']);
        }
    }
}
