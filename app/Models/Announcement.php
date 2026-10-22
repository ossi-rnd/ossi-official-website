<?php

namespace App\Models;

use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An advisory shown as a banner while it is live, to everyone or to chosen roles.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property list<string>|null $audience role values; null = everyone
 * @property bool $is_urgent
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $author
 */
#[Fillable(['title', 'body', 'audience', 'is_urgent', 'starts_at', 'ends_at'])]
class Announcement extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'audience' => null,
        'is_urgent' => false,
        'ends_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'is_urgent' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return MorphMany<SentAlert, $this>
     */
    public function sentAlerts(): MorphMany
    {
        return $this->morphMany(SentAlert::class, 'subject');
    }

    /**
     * Started and not yet ended.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $now = CarbonImmutable::now();

        $query->where('starts_at', '<=', $now)
            ->where(fn (Builder $ends) => $ends->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function isLive(): bool
    {
        $now = CarbonImmutable::now();

        return $this->starts_at->lessThanOrEqualTo($now) && ($this->ends_at === null || $this->ends_at->greaterThan($now));
    }

    public function isFor(User $user): bool
    {
        return $this->audience === null
            || array_intersect($this->audience, array_map(fn (Role $role) => $role->value, $user->roleList())) !== [];
    }

    /**
     * @return list<Role>
     */
    public function audienceRoles(): array
    {
        return array_values(array_filter(array_map(fn (string $value) => Role::tryFrom($value), $this->audience ?? [])));
    }
}
