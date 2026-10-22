<?php

namespace App\Actions\Settings;

use App\Enums\AuditArea;
use App\Enums\Region;
use App\Models\Office;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits an OSSI office (Region → Province → Office) with the spot and
 * radius office staff clock in within. Mirrors SaveClient's checks.
 */
final class SaveOffice
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{region: Region, province: string, name: string, address: string|null, latitude: float|null, longitude: float|null, geofence_meters: int}  $data
     */
    public function handle(?Office $office, array $data, User $actor): Office
    {
        if (($data['latitude'] === null) !== ($data['longitude'] === null)) {
            throw ValidationException::withMessages(['coordinates' => 'Enter both latitude and longitude, or neither.']);
        }

        if ($data['geofence_meters'] < 30 || $data['geofence_meters'] > 2000) {
            throw ValidationException::withMessages(['geofence_meters' => 'Use a radius between 30 m and 2,000 m.']);
        }

        if (! in_array($data['province'], $data['region']->provinces(), true)) {
            throw ValidationException::withMessages(['province' => "Choose a {$data['region']->areaLabel()} in {$data['region']->label()}."]);
        }

        $duplicate = Office::query()
            ->where('region', $data['region']->value)
            ->where('province', $data['province'])
            ->where('name', $data['name'])
            ->when($office, fn ($query, Office $existing) => $query->whereKeyNot($existing->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => "{$data['name']} is already listed in {$data['province']}."]);
        }

        return DB::transaction(function () use ($office, $data, $actor) {
            $isNew = $office === null;
            $office ??= new Office;
            $fields = ['region', 'province', 'name', 'address', 'latitude', 'longitude', 'geofence_meters'];
            $before = $isNew ? [] : [...$office->only($fields), 'region' => $office->region->value];

            $office->fill($data)->save();

            $this->audit->record(AuditArea::Settings, $isNew ? 'office.created' : 'office.updated', "Saved office {$office->name} ({$office->locationLabel()})", $office,
                AuditLogger::diff($before, [...$office->only($fields), 'region' => $office->region->value]), causer: $actor);

            return $office;
        });
    }
}
