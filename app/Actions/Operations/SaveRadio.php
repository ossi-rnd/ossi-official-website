<?php

namespace App\Actions\Operations;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\RadioStatus;
use App\Models\Radio;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a radio on the NTC masterlist. Only a radio in use has a post or a
 * guard; any other status puts it back in stock (both cleared). Audited with the changes.
 */
final class SaveRadio
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{brand: string, model: string, serial_number: string, frequency: string|null, ntc_permit_number: string|null, permit_expires_on: CarbonImmutable|null, status: RadioStatus, client_id: int|null, assigned_user_id: int|null, notes: string|null}  $data
     */
    public function handle(?Radio $radio, array $data, User $actor): Radio
    {
        if (! $actor->can(Ability::ManageRadios->value)) {
            throw new AuthorizationException;
        }

        $data['serial_number'] = strtoupper(trim($data['serial_number']));

        if ($data['status'] !== RadioStatus::InUse) {
            $data['client_id'] = null;
            $data['assigned_user_id'] = null;
        } elseif ($data['client_id'] === null && $data['assigned_user_id'] === null) {
            throw ValidationException::withMessages(['clientId' => 'A radio in use is at a post or with a guard: choose one.']);
        }

        $duplicate = Radio::query()->where('serial_number', $data['serial_number'])->when($radio, fn ($query, Radio $existing) => $query->whereKeyNot($existing->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['serialNumber' => "Serial number {$data['serial_number']} is already on the list."]);
        }

        return DB::transaction(function () use ($radio, $data, $actor) {
            $isNew = $radio === null;
            $radio ??= new Radio;
            $fields = array_keys($data);
            $snapshot = fn (Radio $r) => [...$r->only($fields), 'status' => $r->status->value, 'permit_expires_on' => $r->permit_expires_on?->toDateString()];
            $before = $isNew ? [] : $snapshot($radio);
            $oldHolder = $radio->assigned_user_id;

            $radio->forceFill([...$data, 'permit_expires_on' => $data['permit_expires_on']?->toDateString()])->save();

            $this->audit->record(AuditArea::Operations, $isNew ? 'radio.created' : 'radio.updated', "Saved radio {$radio->brand} {$radio->model} (SN {$radio->serial_number})", $radio,
                AuditLogger::diff($before, $snapshot($radio->refresh())), causer: $actor);

            // Issued to a guard: it's company property in their care, returned at clearance.
            if ($radio->assigned_user_id !== null && $radio->assigned_user_id !== $oldHolder) {
                UpdateNotice::tell($radio->assignedTo()->first(), $actor, "Radio issued to you: {$radio->brand} {$radio->model}",
                    "Serial number {$radio->serial_number}. It's company property in your care: keep it with you on duty and return it when asked.", route('attendance'), 'radio');
            }

            return $radio;
        });
    }
}
