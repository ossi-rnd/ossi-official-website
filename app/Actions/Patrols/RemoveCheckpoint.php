<?php

namespace App\Actions\Patrols;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\PatrolCheckpoint;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Takes a checkpoint off the rounds. One that was ever scanned is only
 * switched off, so the scan history keeps its name; an unused one is deleted.
 * Its QR stops working either way.
 */
final class RemoveCheckpoint
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PatrolCheckpoint $checkpoint, User $actor): void
    {
        $checkpoint->loadMissing('client');

        if (! $actor->can(Ability::ManagePatrols->value) || ($actor->isRegionallyLimited() && $checkpoint->client->region !== $actor->operations_region)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($checkpoint, $actor) {
            $this->audit->record(AuditArea::Operations, 'checkpoint.removed', "Removed checkpoint \"{$checkpoint->name}\" at {$checkpoint->client->name}", $checkpoint, causer: $actor);

            $checkpoint->scans()->exists()
                ? $checkpoint->forceFill(['is_active' => false])->save()
                : $checkpoint->delete();
        });
    }
}
