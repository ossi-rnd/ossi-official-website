<?php

namespace App\Models;

use App\Enums\Region;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client site guards are deployed to, filed by where it is:
 * Region → Province (or NCR city) → Client.
 *
 * @property int $id
 * @property Region $region
 * @property string $province
 * @property string $name
 * @property string|null $site_address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int $geofence_meters
 * @property int $patrol_interval_minutes
 * @property string|null $contact_person
 * @property string|null $contact_number
 */
#[Fillable(['region', 'province', 'name', 'site_address', 'latitude', 'longitude', 'geofence_meters', 'patrol_interval_minutes', 'contact_person', 'contact_number'])]
class Client extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'latitude' => null,
        'longitude' => null,
        'geofence_meters' => 150,
        'patrol_interval_minutes' => 120,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'region' => Region::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geofence_meters' => 'integer',
            'patrol_interval_minutes' => 'integer',
        ];
    }

    /**
     * @return HasMany<EmployeeProfile, $this>
     */
    public function employeeProfiles(): HasMany
    {
        return $this->hasMany(EmployeeProfile::class);
    }

    /**
     * @return HasMany<PatrolCheckpoint, $this>
     */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(PatrolCheckpoint::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Punches here must be within the radius. Without coordinates there is no geofence yet.
     */
    public function hasGeofence(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** "Cavite, Region IV-A" */
    public function locationLabel(): string
    {
        return "{$this->province}, {$this->region->label()}";
    }
}
