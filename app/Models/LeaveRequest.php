<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\LeaveStage;
use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\Concerns\RoutedByStaffKind;
use App\Support\Heads;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property LeaveType $type
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property string $reason
 * @property RequestStatus $status
 * @property LeaveStage|null $stage where a pending request is waiting (see LeaveRouting)
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read User|null $reviewer
 * @property-read Collection<int, LeaveEndorsement> $endorsements
 * @property-read Collection<int, LeaveDocument> $documents
 */
#[Fillable(['type', 'start_date', 'end_date', 'reason'])]
class LeaveRequest extends Model
{
    use RoutedByStaffKind {
        scopeVisibleTo as scopeVisibleByStaffKind;
    }

    /**
     * As for every request (region, office staff only for those who decide them),
     * and a confidential leave (VAWC, RA 9262) only for whoever decides it.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $this->scopeVisibleByStaffKind($query, $viewer);

        $field = $viewer->can(Ability::ReviewFieldLeave->value);
        $allOffice = Heads::seesAllOfficeStaff($viewer);

        if (! ($field && $allOffice)) {
            $query->where(function (Builder $shown) use ($field, $allOffice, $viewer) {
                $shown->whereNotIn('type', LeaveType::confidentialValues());

                if ($field) {
                    $shown->orWhereHas('user', fn (Builder $user) => $user->fieldStaff());
                }

                // An office staffer's confidential leave: only their head (or the President or VP).
                $shown->orWhereHas('user', fn (Builder $user) => $allOffice ? $user->officeStaff() : Heads::constrainOfficeStaffHeadedBy($user, $viewer));
            });
        }
    }

    /**
     * @return HasMany<LeaveDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(LeaveDocument::class)->orderBy('id');
    }

    /** The calendar span, counting both ends. */
    public function days(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    /** The proof this leave needs before approval, or null. */
    public function documentNeeded(): ?string
    {
        return $this->type->document($this->days());
    }

    /** It needs proof and none is attached yet. */
    public function missingDocument(): bool
    {
        return $this->documentNeeded() !== null && $this->documents->isEmpty();
    }

    /** Longer than the law gives (shown to the approver, not refused). */
    public function exceedsEntitlement(): bool
    {
        return $this->type->maxDays() !== null && $this->days() > $this->type->maxDays();
    }

    /**
     * Who may open its documents: the employee, and whoever decides it (HR for guards, the
     * office staffer's head), within their region. Not the endorsers: medical and VAWC papers
     * are sensitive (Data Privacy Act).
     */
    public function documentsVisibleTo(User $viewer): bool
    {
        $this->loadMissing('user.employeeProfile');

        return $viewer->id === $this->user_id || self::canDecideFor($viewer, $this->user);
    }

    /** Guards': HR, after Operations' endorsements. Office staff's: their head (Heads); 'office' is the President or VP's gate. */
    public static function reviewAbilities(): array
    {
        return ['office' => Ability::ReviewOfficeLeave, 'field' => Ability::ReviewFieldLeave];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LeaveType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => RequestStatus::class,
            'stage' => LeaveStage::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Satellite Operation's and Operation HQ's endorsements, in order.
     *
     * @return HasMany<LeaveEndorsement, $this>
     */
    public function endorsements(): HasMany
    {
        return $this->hasMany(LeaveEndorsement::class)->orderBy('id');
    }

    /**
     * Pending requests waiting for THIS person's step (their sidebar badge):
     * a Satellite Operation's region at the Satellite step, Operation HQ at
     * theirs, HR to decide guards', the President or Vice President to decide office staff's.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeAwaitingActionBy(Builder $query, User $viewer): void
    {
        $query->pending()->where('user_id', '!=', $viewer->id)->visibleTo($viewer)->where(function (Builder $mine) use ($viewer) {
            $mine->whereRaw('1 = 0');

            if ($viewer->can(Ability::ReviewOfficeLeave->value)) {
                $mine->orWhere('stage', LeaveStage::Executive->value);
            }

            if ($viewer->can(Ability::ReviewFieldLeave->value)) {
                $mine->orWhere('stage', LeaveStage::Hr->value);
            }

            if ($viewer->hasRole(Role::OperationsHq)) {
                $mine->orWhere('stage', LeaveStage::OperationsHq->value);
            }

            if ($viewer->hasRole(Role::SatelliteOperations) && $viewer->operations_region !== null) {
                // visibleTo() already keeps a Satellite to its own region's guards.
                $mine->orWhere('stage', LeaveStage::Satellite->value);
            }
        });
    }

    /** "With Operation HQ", for a pending request; null once decided. */
    public function waitingLabel(): ?string
    {
        return $this->status === RequestStatus::Pending && $this->stage !== null ? 'With '.$this->stage->label() : null;
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
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Requests whose [start_date, end_date] overlaps the given range.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): void
    {
        $query->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString());
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', RequestStatus::Approved);
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', RequestStatus::Pending);
    }

    public function dateRangeLabel(): string
    {
        return $this->start_date->equalTo($this->end_date)
            ? $this->start_date->format('M j, Y')
            : $this->start_date->format('M j').' – '.$this->end_date->format('M j, Y');
    }

    /** "1 day", "3 days": the calendar span, for messages (pay counts days separately). */
    public function calendarDaysLabel(): string
    {
        $days = $this->days();

        return $days === 1 ? '1 day' : "{$days} days";
    }
}
