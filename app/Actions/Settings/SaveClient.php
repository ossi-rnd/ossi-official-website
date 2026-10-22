<?php

namespace App\Actions\Settings;

use App\Enums\AuditArea;
use App\Enums\Region;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a client site (Region → Province → Client). Guards deployed
 * there carry the client's name as their detachment, so a rename updates them
 * in the same transaction.
 */
final class SaveClient
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{region: Region, province: string, name: string, site_address: string|null, latitude: float|null, longitude: float|null, geofence_meters: int, contact_person: string|null, contact_number: string|null}  $data
     */
    public function handle(?Client $client, array $data, User $actor): Client
    {
        if (($data['latitude'] === null) !== ($data['longitude'] === null)) {
            throw ValidationException::withMessages(['coordinates' => 'Enter both latitude and longitude, or neither.']);
        }

        if ($data['geofence_meters'] < 30 || $data['geofence_meters'] > 2000) {
            throw ValidationException::withMessages(['geofence_meters' => 'Use a radius between 30 m and 2,000 m.']);
        }

        // Re-checked here, not only in the form: the province must belong to the region.
        if (! in_array($data['province'], $data['region']->provinces(), true)) {
            throw ValidationException::withMessages(['province' => "Choose a {$data['region']->areaLabel()} in {$data['region']->label()}."]);
        }

        $duplicate = Client::query()
            ->where('region', $data['region']->value)
            ->where('province', $data['province'])
            ->where('name', $data['name'])
            ->when($client, fn ($query, Client $existing) => $query->whereKeyNot($existing->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => "{$data['name']} is already listed in {$data['province']}. Add the branch to the name (e.g. \"{$data['name']} – Imus\")."]);
        }

        return DB::transaction(function () use ($client, $data, $actor) {
            $isNew = $client === null;
            $client ??= new Client;
            $fields = ['region', 'province', 'name', 'site_address', 'latitude', 'longitude', 'geofence_meters', 'contact_person', 'contact_number'];
            $before = $isNew ? [] : [...$client->only($fields), 'region' => $client->region->value];
            $oldName = $isNew ? null : $client->name;

            $client->fill($data)->save();

            if ($oldName !== null && $oldName !== $client->name) {
                EmployeeProfile::query()->where('client_id', $client->id)->update(['detachment' => $client->name]);
            }

            $this->audit->record(AuditArea::Settings, $isNew ? 'client.created' : 'client.updated', "Saved client {$client->name} ({$client->locationLabel()})", $client,
                AuditLogger::diff($before, [...$client->only($fields), 'region' => $client->region->value]), causer: $actor);

            return $client;
        });
    }
}
