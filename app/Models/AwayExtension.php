<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "I need more time": how much was added to an absence, and why. Nothing is
 * fillable: ExtendAway sets every column.
 *
 * @property int $id
 * @property int $away_log_id
 * @property int $minutes
 * @property string $reason
 * @property CarbonImmutable $previous_back_at
 * @property CarbonImmutable $new_back_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AwayLog $awayLog
 */
class AwayExtension extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'previous_back_at' => 'datetime',
            'new_back_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AwayLog, $this>
     */
    public function awayLog(): BelongsTo
    {
        return $this->belongsTo(AwayLog::class);
    }
}
