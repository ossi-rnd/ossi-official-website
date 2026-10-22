<?php

namespace App\Actions\Patrols;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\PatrolCheckpoint;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * "New sticker": gives a checkpoint a new random code, so the old sticker,
 * and every photo of it a guard may have kept to scan from elsewhere,
 * stops working. Print the new QR and swap it on the wall.
 */
final class RenewCheckpointCode
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PatrolCheckpoint $checkpoint, User $actor): PatrolCheckpoint
    {
        $checkpoint->loadMissing('client');

        if (! $actor->can(Ability::ManagePatrols->value) || ($actor->isRegionallyLimited() && $checkpoint->client->region !== $actor->operations_region)) {
            throw new AuthorizationException;
        }

        $checkpoint->forceFill(['code' => Str::random(32)])->save();

        $this->audit->record(AuditArea::Operations, 'checkpoint.renewed', "New sticker code for \"{$checkpoint->name}\" at {$checkpoint->client->name}: the old QR no longer works", $checkpoint, causer: $actor);

        return $checkpoint;
    }
}
