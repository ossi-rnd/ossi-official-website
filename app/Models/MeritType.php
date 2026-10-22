<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of merit or demerit and its points, kept by HR, so the same act gets
 * the same points whoever records it.
 *
 * @property int $id
 * @property string $name
 * @property string $kind merit | demerit
 * @property int $points always positive; the kind gives the sign
 * @property string|null $description
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 */
class MeritType extends Model
{
    public const MERIT = 'merit';

    public const DEMERIT = 'demerit';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Signed: + for a merit, − for a demerit. */
    public function signedPoints(): int
    {
        return $this->kind === self::DEMERIT ? -$this->points : $this->points;
    }

    /**
     * @param  Builder<MeritType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
