<?php

namespace App\Actions\Patrols;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\Client;
use App\Models\PatrolCheckpoint;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds or renames a checkpoint at a client. A new one gets a random code,
 * which is what the printed QR holds; renaming keeps the code, so stickers
 * already on the wall still work.
 */
final class SaveCheckpoint
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(?PatrolCheckpoint $checkpoint, Client $client, string $name, ?float $latitude, ?float $longitude, int $sortOrder, User $actor): PatrolCheckpoint
    {
        if (! $actor->can(Ability::ManagePatrols->value) || ($actor->isRegionallyLimited() && $client->region !== $actor->operations_region)) {
            throw new AuthorizationException;
        }

        if (($latitude === null) !== ($longitude === null)) {
            throw ValidationException::withMessages(['checkpointCoordinates' => 'Enter both latitude and longitude, or neither.']);
        }

        return DB::transaction(function () use ($checkpoint, $client, $name, $latitude, $longitude, $sortOrder, $actor) {
            $isNew = $checkpoint === null;
            $checkpoint ??= new PatrolCheckpoint;

            $checkpoint->forceFill([
                'client_id' => $client->id,
                'name' => trim($name),
                'code' => $checkpoint->code ?? Str::random(32),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'sort_order' => $sortOrder,
            ])->save();

            $this->audit->record(AuditArea::Operations, $isNew ? 'checkpoint.created' : 'checkpoint.updated', "Checkpoint \"{$checkpoint->name}\" at {$client->name}", $checkpoint, causer: $actor);

            return $checkpoint;
        });
    }
}
