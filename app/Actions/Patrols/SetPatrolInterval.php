<?php

namespace App\Actions\Patrols;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\Client;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * How often a full round of a client's checkpoints is due (every 2 hours by default).
 */
final class SetPatrolInterval
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Client $client, int $minutes, User $actor): Client
    {
        if (! $actor->can(Ability::ManagePatrols->value) || ($actor->isRegionallyLimited() && $client->region !== $actor->operations_region)) {
            throw new AuthorizationException;
        }

        if ($minutes < 30 || $minutes > 720) {
            throw ValidationException::withMessages(['interval' => 'Use a round every 30 minutes to 12 hours.']);
        }

        $before = $client->patrol_interval_minutes;
        $client->forceFill(['patrol_interval_minutes' => $minutes])->save();

        $this->audit->record(AuditArea::Operations, 'patrol.interval', "Patrol round at {$client->name}: every {$minutes} min", $client, [
            'old' => ['patrol_interval_minutes' => $before], 'new' => ['patrol_interval_minutes' => $minutes],
        ], causer: $actor);

        return $client;
    }
}
