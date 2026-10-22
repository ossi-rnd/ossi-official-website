<?php

namespace App\Models;

use App\Enums\RadioStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One two-way radio on the NTC masterlist: what it is, its NTC permit and when that
 * expires, and where it is (a client post, a guard, or in stock). Nothing is
 * fillable: SaveRadio sets every column.
 *
 * @property int $id
 * @property string $brand
 * @property string $model
 * @property string $serial_number
 * @property string|null $frequency
 * @property string|null $ntc_permit_number
 * @property CarbonImmutable|null $permit_expires_on
 * @property RadioStatus $status
 * @property int|null $client_id
 * @property int|null $assigned_user_id
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Client|null $client
 * @property-read User|null $assignedTo
 */
class Radio extends Model
{
    /** Days before the permit's expiry that count as "due soon" on screen. */
    public const DUE_SOON_DAYS = 30;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'spare',
        'client_id' => null,
        'assigned_user_id' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permit_expires_on' => 'date',
            'status' => RadioStatus::class,
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
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Radios whose permit still matters (not disposed), expiring on or before that day.
     *
     * @param  Builder<Radio>  $query
     */
    public function scopePermitDueBy(Builder $query, CarbonImmutable $day): void
    {
        $query->where('status', '!=', RadioStatus::Disposed)
            ->whereNotNull('permit_expires_on')
            ->whereDate('permit_expires_on', '<=', $day->toDateString());
    }

    /** Days until the permit expires (negative once it has), or null without a date. */
    public function daysToExpiry(CarbonImmutable $today): ?int
    {
        return $this->permit_expires_on === null ? null : (int) $today->diffInDays($this->permit_expires_on, false);
    }

    /** "Metrobank – Imus · Main Entrance", "Juan Dela Cruz", or "In stock". */
    public function whereabouts(): string
    {
        return match (true) {
            $this->assignedTo !== null && $this->client !== null => "{$this->assignedTo->name} · {$this->client->name}",
            $this->assignedTo !== null => $this->assignedTo->name,
            $this->client !== null => $this->client->name,
            default => $this->status === RadioStatus::InUse ? 'Not set' : 'In stock',
        };
    }
}
