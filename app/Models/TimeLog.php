<?php

namespace App\Models;

use App\Enums\TimeLogStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\TimeLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nothing is fillable: every column is the result of a punch, written by RecordPunch.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $shift_id
 * @property CarbonImmutable $log_date
 * @property int|null $duty_assignment_id
 * @property int|null $recorded_by
 * @property string|null $manual_reason
 * @property CarbonImmutable|null $clock_in_at
 * @property CarbonImmutable|null $clock_out_at
 * @property TimeLogStatus $status
 * @property int $late_minutes
 * @property int $undertime_minutes
 * @property int $overtime_minutes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read Shift|null $shift
 * @property-read Collection<int, TimeLogPunch> $punches
 */
class TimeLog extends Model
{
    /** @use HasFactory<TimeLogFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'incomplete',
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'overtime_minutes' => 0,
        'duty_assignment_id' => null,
        'recorded_by' => null,
        'manual_reason' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'status' => TimeLogStatus::class,
            'late_minutes' => 'integer',
            'undertime_minutes' => 'integer',
            'overtime_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * The rostered duty this attendance was for (none before rosters, or off-roster).
     *
     * @return BelongsTo<DutyAssignment, $this>
     */
    public function dutyAssignment(): BelongsTo
    {
        return $this->belongsTo(DutyAssignment::class);
    }

    /**
     * Entered by Operations (phone or GPS failed), not punched with a selfie.
     */
    public function isManual(): bool
    {
        return $this->recorded_by !== null;
    }

    /**
     * @return HasMany<TimeLogPunch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(TimeLogPunch::class);
    }

    /**
     * @param  Builder<TimeLog>  $query
     */
    public function scopeBetween(Builder $query, CarbonInterface $start, CarbonInterface $end): void
    {
        $query->whereDate('log_date', '>=', $start->toDateString())
            ->whereDate('log_date', '<=', $end->toDateString());
    }

    public function isOpen(): bool
    {
        return $this->clock_in_at !== null && $this->clock_out_at === null;
    }

    public function workedMinutes(): ?int
    {
        if ($this->clock_in_at === null || $this->clock_out_at === null) {
            return null;
        }

        return (int) $this->clock_in_at->diffInMinutes($this->clock_out_at);
    }
}
