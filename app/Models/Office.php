<?php

namespace App\Models;

use App\Enums\Region;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of OSSI's own offices (head office, a branch), where office staff work:
 * Region → Province → Office, with the spot and radius their clock-in must be
 * inside. Not a client: clients are where guards are deployed.
 *
 * @property int $id
 * @property Region $region
 * @property string $province
 * @property string $name
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int $geofence_meters
 */
#[Fillable(['region', 'province', 'name', 'address', 'latitude', 'longitude', 'geofence_meters'])]
class Office extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'address' => null,
        'latitude' => null,
        'longitude' => null,
        'geofence_meters' => 150,
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
        ];
    }

    /**
     * @return HasMany<EmployeeProfile, $this>
     */
    public function employeeProfiles(): HasMany
    {
        return $this->hasMany(EmployeeProfile::class);
    }

    /** Clock-ins here must be within the radius. Without coordinates there is no geofence yet. */
    public function hasGeofence(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** "Quezon City, NCR" */
    public function locationLabel(): string
    {
        return "{$this->province}, {$this->region->label()}";
    }
}
