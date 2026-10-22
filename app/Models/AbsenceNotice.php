<?php

namespace App\Models;

use App\Enums\AbsenceNoticeStatus;
use App\Enums\AbsenceSource;
use App\Enums\ReimbursementStatus;
use App\Services\Attendance\AttendanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "I can't report for duty on this day": filed ahead by a guard so
 * Operations can send a reliever to the post, or raised by the system when
 * a guard on the roster neither clocked in nor reported (a no-show).
 * Nothing is fillable: the Actions set every column.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $duty_date
 * @property string $reason
 * @property AbsenceSource $source
 * @property int|null $client_id
 * @property string|null $detachment
 * @property string|null $post
 * @property int|null $duty_assignment_id
 * @property AbsenceNoticeStatus $status
 * @property int|null $reliever_id
 * @property int|null $covered_by
 * @property CarbonImmutable|null $covered_at
 * @property string|null $cover_note
 * @property CarbonImmutable|null $arrived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read User|null $reliever
 * @property-read User|null $coveredBy
 * @property-read Client|null $client
 * @property-read DutyAssignment|null $dutyAssignment
 * @property-read Collection<int, Reimbursement> $fareClaims
 */
class AbsenceNotice extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'source' => 'guard',
        'duty_assignment_id' => null,
        'reliever_id' => null,
        'covered_by' => null,
        'covered_at' => null,
        'cover_note' => null,
        'arrived_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duty_date' => 'date',
            'status' => AbsenceNoticeStatus::class,
            'source' => AbsenceSource::class,
            'covered_at' => 'datetime',
            'arrived_at' => 'datetime',
        ];
    }

    public function isNoShow(): bool
    {
        return $this->source === AbsenceSource::NoShow;
    }

    /**
     * The duty this notice is about: the one that was missed (a no-show), else
     * the guard's duty on the roster that day.
     */
    public function duty(): ?DutyAssignment
    {
        if ($this->duty_assignment_id !== null) {
            return $this->dutyAssignment;
        }

        return DutyAssignment::query()->with('shift')->where('user_id', $this->user_id)->whereDate('duty_date', $this->duty_date->toDateString())->first();
    }

    /**
     * When the post needs someone: the duty's shift on that day, else the
     * guard's usual shift (a guard not on the roster).
     *
     * @return array{start: CarbonImmutable, graceEnd: CarbonImmutable, end: CarbonImmutable, shift: Shift}
     */
    public function dutyWindow(): array
    {
        $this->loadMissing(['dutyAssignment.shift', 'user.employeeProfile.shift']);
        $shift = $this->duty()->shift ?? $this->user->employeeProfile->shift ?? Shift::fallback();
        [$start, $end] = app(AttendanceCalculator::class)->shiftWindow($shift, $this->duty_date);

        return ['start' => $start, 'graceEnd' => $start->addMinutes($shift->grace_minutes), 'end' => $end, 'shift' => $shift];
    }

    /**
     * @return BelongsTo<DutyAssignment, $this>
     */
    public function dutyAssignment(): BelongsTo
    {
        return $this->belongsTo(DutyAssignment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reliever(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reliever_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function coveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'covered_by');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Still needing a reliever, today or later.
     *
     * @param  Builder<AbsenceNotice>  $query
     */
    public function scopeNeedsReliever(Builder $query): void
    {
        $query->where('status', AbsenceNoticeStatus::Open)->whereDate('duty_date', '>=', today()->toDateString());
    }

    /**
     * Notices this viewer may handle: all, or only their region's (Satellite Operation).
     *
     * @param  Builder<AbsenceNotice>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->whereHas('user', fn (Builder $user) => $user->visibleTo($viewer));
    }

    /** "Metrobank – Imus · Main Entrance" */
    public function placeLabel(): string
    {
        return collect([$this->detachment, $this->post])->filter()->implode(' · ') ?: 'No post on record';
    }

    /**
     * Deployment claims for the reliever's fare (roadmap 5), filed from this relief.
     *
     * @return HasMany<Reimbursement, $this>
     */
    public function fareClaims(): HasMany
    {
        return $this->hasMany(Reimbursement::class);
    }

    /** The fare claim that stands (not cancelled or rejected): one per relief. */
    public function activeFareClaim(): ?Reimbursement
    {
        return $this->fareClaims->first(fn (Reimbursement $claim) => ! in_array($claim->status, [ReimbursementStatus::Cancelled, ReimbursementStatus::Rejected], true));
    }

    /** "Relief at Metrobank – Imus · Main Entrance, Oct 15, for Romeo Ocampo": the claim's "Which deployment". */
    public function reliefLabel(): string
    {
        $this->loadMissing('user');

        return "Relief at {$this->placeLabel()}, {$this->duty_date->format('M j')}, for {$this->user->name}";
    }

    /** A relief done (today or before) whose fare could still be claimed (the receipt window). */
    public function fareClaimable(): bool
    {
        return $this->status === AbsenceNoticeStatus::Covered
            && $this->reliever_id !== null
            && ! $this->duty_date->isAfter(today())
            && ! $this->duty_date->isBefore(today()->subDays(Reimbursement::fileWithinDays()));
    }

    /**
     * Reliefs done in the receipt window with no fare claim standing: the suggestions on Deployment Costs.
     *
     * @param  Builder<AbsenceNotice>  $query
     */
    public function scopeAwaitingFareClaim(Builder $query): void
    {
        $query->where('status', AbsenceNoticeStatus::Covered)->whereNotNull('reliever_id')
            ->whereDate('duty_date', '<=', today()->toDateString())
            ->whereDate('duty_date', '>=', today()->subDays(Reimbursement::fileWithinDays())->toDateString())
            ->whereDoesntHave('fareClaims', fn (Builder $claim) => $claim->whereNotIn('status', [ReimbursementStatus::Cancelled->value, ReimbursementStatus::Rejected->value]));
    }
}
