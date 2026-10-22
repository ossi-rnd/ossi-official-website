<?php

namespace App\Models;

use App\Enums\ThirteenthMonthStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One 13th month payout for a year. Written only by the ThirteenthMonth actions.
 *
 * @property int $id
 * @property int $year
 * @property CarbonImmutable $covers_until
 * @property CarbonImmutable $pay_date
 * @property ThirteenthMonthStatus $status
 * @property CarbonImmutable|null $computed_at
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, ThirteenthMonthPayout> $payouts
 */
class ThirteenthMonthRun extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'covers_until' => 'date',
            'pay_date' => 'date',
            'computed_at' => 'datetime',
            'status' => ThirteenthMonthStatus::class,
        ];
    }

    /**
     * @return HasMany<ThirteenthMonthPayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(ThirteenthMonthPayout::class);
    }

    public function label(): string
    {
        return "{$this->year} 13th month · up to {$this->covers_until->format('M j')}";
    }
}
