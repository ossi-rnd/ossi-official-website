<?php

namespace App\Models;

use App\Enums\AwayReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Someone away from their post (or the office) during work: why, from when,
 * when they said they'd be back (and any time added), until when, and
 * whether a supervisor approved it. Nothing is fillable: the Actions set
 * every column.
 *
 * What counts as a problem is deliberately narrow: running late is normal
 * (traffic, queues), so it's only a heads-up. A supervisor is alerted when
 * nobody knows where they are (no location for a while) or they're well past
 * their own time without asking for more.
 *
 * @property int $id
 * @property int $user_id
 * @property int $time_log_id
 * @property AwayReason $reason
 * @property string|null $note
 * @property CarbonImmutable $left_at
 * @property string|null $left_latitude
 * @property string|null $left_longitude
 * @property CarbonImmutable|null $expected_back_at
 * @property CarbonImmutable|null $returned_at
 * @property string|null $returned_latitude
 * @property string|null $returned_longitude
 * @property int|null $returned_distance_meters
 * @property CarbonImmutable|null $auto_closed_at closed at the duty's end because nobody ended it
 * @property CarbonImmutable|null $locations_pruned_at its GPS trail was deleted (retention)
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read TimeLog $timeLog
 * @property-read User|null $approver
 * @property-read AwayLocationUpdate|null $latestLocation
 * @property-read Collection<int, AwayExtension> $extensions
 * @property-read int|null $extensions_count
 */
class AwayLog extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'note' => null,
        'returned_at' => null,
        'returned_distance_meters' => null,
        'approved_by' => null,
        'approved_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => AwayReason::class,
            'left_at' => 'datetime',
            'expected_back_at' => 'datetime',
            'returned_at' => 'datetime',
            'auto_closed_at' => 'datetime',
            'locations_pruned_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * How the absence stands, as a badge [tone, text], for the lists, the
     * location window and the employee record alike.
     *
     * @return array{0: string, 1: string}
     */
    public function outcome(CarbonImmutable $now): array
    {
        $past = $this->minutesPastExpected($now);

        return match (true) {
            $this->approved_at !== null => ['blue', 'Approved by '.($this->approver->name ?? '—')],
            $this->auto_closed_at !== null => ['red', 'Never ended: closed at duty end'],
            $this->returned_at !== null && $past > 0 => ['amber', "Back {$past} min after ".$this->expectedBackAt()->format('g:i A')],
            $this->returned_at !== null => ['gray', 'Back on time'],
            $this->isLocationOverdue($now) => ['red', 'No location for '.(int) $this->lastSeenAt()->diffInMinutes($now).' min'],
            $this->isLate($now) => ['red', "{$past} min past their time"],
            $past > 0 => ['amber', "Running late · {$past} min past their time"],
            default => ['gray', 'Out now'],
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<TimeLog, $this>
     */
    public function timeLog(): BelongsTo
    {
        return $this->belongsTo(TimeLog::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<AwayLocationUpdate, $this>
     */
    public function locationUpdates(): HasMany
    {
        return $this->hasMany(AwayLocationUpdate::class);
    }

    /**
     * @return HasOne<AwayLocationUpdate, $this>
     */
    public function latestLocation(): HasOne
    {
        return $this->hasOne(AwayLocationUpdate::class)->latestOfMany('recorded_at');
    }

    /**
     * @return HasMany<AwayExtension, $this>
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(AwayExtension::class)->orderBy('id');
    }

    /** When they said they'd be back, with any time added; older rows: the reason's usual time. */
    public function expectedBackAt(): CarbonImmutable
    {
        return $this->expected_back_at ?? $this->left_at->addMinutes($this->reason->usualMinutes());
    }

    /** Minutes past the expected time (0 when not past it); measured to the return once back. */
    public function minutesPastExpected(CarbonImmutable $now): int
    {
        $end = $this->returned_at ?? $now;

        return $end->greaterThan($this->expectedBackAt()) ? (int) $this->expectedBackAt()->diffInMinutes($end) : 0;
    }

    /** Past the time they said, not approved: a heads-up (amber), not a violation. */
    public function isPastExpected(CarbonImmutable $now): bool
    {
        return $this->approved_at === null && $this->minutesPastExpected($now) > 0;
    }

    /** Well past their own time and still out, with no more time asked: worth a call. */
    public function isLate(CarbonImmutable $now): bool
    {
        return $this->returned_at === null && $this->approved_at === null
            && $this->minutesPastExpected($now) >= (int) config('attendance.away.late_alert_minutes');
    }

    /** When they last said where they are: the latest update, or leaving. */
    public function lastSeenAt(): CarbonImmutable
    {
        $this->loadMissing('latestLocation');

        return $this->latestLocation->recorded_at ?? $this->left_at;
    }

    /** Time for a location update: a reminder on their phone only. */
    public function isLocationDue(CarbonImmutable $now): bool
    {
        return $this->returned_at === null && $this->lastSeenAt()->diffInMinutes($now) >= (int) config('attendance.away.location_update_minutes');
    }

    /** No location for long enough that the supervisor is told. */
    public function isLocationOverdue(CarbonImmutable $now): bool
    {
        return $this->returned_at === null && $this->lastSeenAt()->diffInMinutes($now) >= (int) config('attendance.away.location_alert_minutes');
    }

    /** Nobody knows where they are, or they're well past their time: the red ones. */
    public function needsAttention(CarbonImmutable $now): bool
    {
        return $this->isLocationOverdue($now) || $this->isLate($now);
    }

    /**
     * Still away.
     *
     * @param  Builder<AwayLog>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('returned_at');
    }

    public function minutesAway(CarbonImmutable $now): int
    {
        return (int) $this->left_at->diffInMinutes($this->returned_at ?? $now);
    }
}
