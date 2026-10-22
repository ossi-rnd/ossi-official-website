<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a guard was while away from the post, shared from My Attendance.
 * Nothing is fillable: UpdateAwayLocation sets every column.
 *
 * @property int $id
 * @property int $away_log_id
 * @property CarbonImmutable $recorded_at
 * @property string $latitude
 * @property string $longitude
 * @property int|null $accuracy_meters
 * @property int|null $distance_meters
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AwayLog $awayLog
 */
class AwayLocationUpdate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_meters' => 'integer',
            'distance_meters' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AwayLog, $this>
     */
    public function awayLog(): BelongsTo
    {
        return $this->belongsTo(AwayLog::class);
    }

    /** A Google Maps link to the spot, for Operations (same form as a punch's, TimeLogPunch::mapUrl). */
    public function mapUrl(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.(float) $this->latitude.','.(float) $this->longitude;
    }
}
