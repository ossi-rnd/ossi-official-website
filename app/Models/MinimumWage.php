<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The daily minimum wage from a date (one wage order). Written only by
 * App\Actions\Payroll\SaveMinimumWage.
 *
 * @property int $id
 * @property CarbonImmutable $effective_from
 * @property string $daily_amount
 * @property string|null $wage_order
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $creator
 */
class MinimumWage extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'wage_order' => null,
        'created_by' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'daily_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
