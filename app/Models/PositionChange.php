<?php

namespace App\Models;

use App\Enums\Position;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of an employee's position (roadmap 22). Pay uses the position held
 * on each cut-off's start date, from this history (EffectiveRatesQuery).
 *
 * @property int $id
 * @property int $employee_profile_id
 * @property Position|null $from_position
 * @property Position $to_position
 * @property string $kind promotion | transfer | edit
 * @property CarbonImmutable $effective_on
 * @property string|null $reason
 * @property int|null $recorded_by
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable $created_at
 * @property-read EmployeeProfile $profile
 * @property-read User|null $recorder
 */
class PositionChange extends Model
{
    public const PROMOTION = 'promotion';

    public const TRANSFER = 'transfer';

    public const EDIT = 'edit';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_position' => Position::class,
            'to_position' => Position::class,
            'effective_on' => 'date',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Recorded, but its day hasn't come yet.
     *
     * @param  Builder<PositionChange>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('applied_at');
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::PROMOTION => 'Promotion',
            self::TRANSFER => 'Change of position',
            default => 'Edited on the record',
        };
    }

    /**
     * The position each profile held on a date, from the history: the last change
     * that started on or before it; before the first change, the position it
     * changed from; with no history, the record's current position.
     *
     * @param  iterable<EmployeeProfile>  $profiles
     * @return array<int, Position|null> keyed by employee_profile_id
     */
    public static function positionsOn(iterable $profiles, CarbonInterface $date): array
    {
        $current = [];
        foreach ($profiles as $profile) {
            $current[$profile->id] = $profile->position;
        }

        if ($current === []) {
            return [];
        }

        $byProfile = self::query()
            ->whereIn('employee_profile_id', array_keys($current))
            ->orderBy('effective_on')
            ->orderBy('id')
            ->get(['employee_profile_id', 'from_position', 'to_position', 'effective_on'])
            ->groupBy('employee_profile_id');

        $positions = $current;
        $day = $date->toDateString();

        foreach ($byProfile as $profileId => $changes) {
            $held = $changes->first()?->from_position;
            foreach ($changes as $change) {
                if ($change->effective_on->toDateString() <= $day) {
                    $held = $change->to_position;
                }
            }
            $positions[(int) $profileId] = $held;
        }

        return $positions;
    }
}
