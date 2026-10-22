<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One checkpoint scan: when, where (GPS), and the camera frame that read the QR.
 *
 * @property int $id
 * @property int $user_id
 * @property int $patrol_checkpoint_id
 * @property int $time_log_id
 * @property CarbonImmutable $scanned_at
 * @property string $latitude
 * @property string $longitude
 * @property int|null $accuracy_meters
 * @property int|null $distance_meters
 * @property string $photo_path
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PatrolCheckpoint $checkpoint
 * @property-read User $user
 * @property-read TimeLog $timeLog
 */
class PatrolScan extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_meters' => 'integer',
            'distance_meters' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PatrolCheckpoint, $this>
     */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(PatrolCheckpoint::class, 'patrol_checkpoint_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<TimeLog, $this>
     */
    public function timeLog(): BelongsTo
    {
        return $this->belongsTo(TimeLog::class);
    }
}
