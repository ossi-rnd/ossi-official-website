<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A QR sticker at a client's site. The printed QR holds "OSSI-CP:{code}";
 * the code is random, so a checkpoint can't be guessed or made up.
 * Nothing is fillable: the Actions set every column.
 *
 * @property int $id
 * @property int $client_id
 * @property string $name
 * @property string $code
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Client $client
 */
class PatrolCheckpoint extends Model
{
    public const QR_PREFIX = 'OSSI-CP:';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'latitude' => null,
        'longitude' => null,
        'sort_order' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<PatrolScan, $this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(PatrolScan::class);
    }

    /**
     * @param  Builder<PatrolCheckpoint>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** What the QR sticker encodes. */
    public function qrPayload(): string
    {
        return self::QR_PREFIX.$this->code;
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
