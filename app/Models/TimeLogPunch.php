<?php

namespace App\Models;

use App\Enums\PunchType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $time_log_id
 * @property PunchType $type
 * @property CarbonImmutable $punched_at
 * @property string $photo_path
 * @property string $latitude
 * @property string $longitude
 * @property int|null $accuracy_meters
 * @property string|null $address
 * @property string|null $device_hash SHA-256 of the phone's cookie code (UserDevice::hash)
 * @property string|null $device_label "Android · Chrome"
 * @property bool $other_device not the person's registered phone: flagged for Operations and HR
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read TimeLog $timeLog
 */
#[Fillable(['type', 'punched_at', 'photo_path', 'latitude', 'longitude', 'accuracy_meters', 'address', 'device_hash', 'device_label', 'other_device'])]
class TimeLogPunch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PunchType::class,
            'punched_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_meters' => 'integer',
            'other_device' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TimeLog, $this>
     */
    public function timeLog(): BelongsTo
    {
        return $this->belongsTo(TimeLog::class);
    }

    /**
     * The photo is never public: this route streams it after a policy check.
     */
    public function photoUrl(): string
    {
        return route('punches.photo', $this);
    }

    /**
     * Google Maps at the punch (Maps URLs, api=1): satellite, 3D and Street View, and on a
     * phone it opens the Maps app. Only the coordinates go to Google, when someone clicks.
     */
    public function mapUrl(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.(float) $this->latitude.','.(float) $this->longitude;
    }
}
