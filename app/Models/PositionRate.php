<?php

namespace App\Models;

use App\Enums\Position;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company's daily rate for one position, from a date. Written only by SetPositionRate.
 *
 * @property int $id
 * @property Position $position
 * @property string $daily_rate
 * @property CarbonImmutable $effective_from
 * @property string|null $note
 * @property int|null $set_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $setter
 */
class PositionRate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => Position::class,
            'daily_rate' => 'decimal:2',
            'effective_from' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
