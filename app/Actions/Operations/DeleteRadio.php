<?php

namespace App\Actions\Operations;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\Radio;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Removes a radio added by mistake. A radio that left service is marked Disposed
 * (or Lost) instead, so the masterlist keeps its history for the NTC.
 */
final class DeleteRadio
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Radio $radio, User $actor): void
    {
        if (! $actor->can(Ability::ManageRadios->value)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($radio, $actor) {
            $this->audit->record(AuditArea::Operations, 'radio.deleted', "Deleted radio {$radio->brand} {$radio->model} (SN {$radio->serial_number})", $radio,
                ['old' => ['serial_number' => $radio->serial_number, 'ntc_permit_number' => $radio->ntc_permit_number]], causer: $actor);

            $radio->delete();
        });
    }
}
