<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One file in Admin's library. A renewal is a NEW row; the old one points at
 * it through replaced_by_id, so the list shows current copies and each keeps
 * its history. Nothing is fillable: the Actions set every column.
 *
 * @property int $id
 * @property DocumentCategory $category
 * @property string $title
 * @property int|null $client_id
 * @property string|null $reference_number
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $expires_on
 * @property string|null $notes
 * @property string $file_path
 * @property string $file_name
 * @property string $file_mime
 * @property int $file_size
 * @property int|null $uploaded_by
 * @property int|null $replaced_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Client|null $client
 * @property-read User|null $uploader
 * @property-read CompanyDocument|null $replacedBy
 * @property-read int|null $earlier_copies_count
 */
class CompanyDocument extends Model
{
    /** Days before the due date that count as "due soon" on screen. */
    public const DUE_SOON_DAYS = 30;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'client_id' => null,
        'replaced_by_id' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'issued_on' => 'date',
            'expires_on' => 'date',
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<CompanyDocument, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /**
     * The copy this one renewed (at most one: each renewal replaces the current copy).
     *
     * @return HasMany<CompanyDocument, $this>
     */
    public function earlierCopies(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_id');
    }

    /**
     * Not replaced by a renewal.
     *
     * @param  Builder<CompanyDocument>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('replaced_by_id');
    }

    public function daysToExpiry(CarbonImmutable $today): ?int
    {
        return $this->expires_on === null ? null : (int) $today->diffInDays($this->expires_on, false);
    }

    /**
     * 'expired', 'due_soon', 'valid', or null when it never expires.
     */
    public function expiryState(CarbonImmutable $today): ?string
    {
        $days = $this->daysToExpiry($today);

        return match (true) {
            $days === null => null,
            $days < 0 => 'expired',
            $days <= self::DUE_SOON_DAYS => 'due_soon',
            default => 'valid',
        };
    }
}
