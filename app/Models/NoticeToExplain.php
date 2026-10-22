<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\NteOutcome;
use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Notice to Explain and its decision: the twin-notice rule (roadmap 30).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $issued_by
 * @property string $subject
 * @property CarbonImmutable $incident_date
 * @property string $details
 * @property string $rule_violated
 * @property NteOutcome $possible_penalty
 * @property CarbonImmutable $explain_by
 * @property string $status issued | explained | decided
 * @property CarbonImmutable|null $acknowledged_at
 * @property string|null $explanation
 * @property bool $wants_hearing
 * @property CarbonImmutable|null $explained_at
 * @property CarbonImmutable|null $hearing_at
 * @property string|null $hearing_place
 * @property NteOutcome|null $decision
 * @property string|null $decision_reason
 * @property int|null $suspension_days
 * @property CarbonImmutable|null $suspension_from
 * @property int|null $merit_record_id
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $decision_acknowledged_at
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read User|null $issuer
 * @property-read User|null $decider
 * @property-read MeritRecord|null $demerit
 */
class NoticeToExplain extends Model
{
    protected $table = 'notices_to_explain';

    /** At least 5 calendar days to explain (King of Kings Transport v. Mamac). */
    public const MIN_DAYS = 5;

    public const ISSUED = 'issued';

    public const EXPLAINED = 'explained';

    public const DECIDED = 'decided';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::ISSUED,
        'wants_hearing' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'possible_penalty' => NteOutcome::class,
            'explain_by' => 'date',
            'acknowledged_at' => 'datetime',
            'wants_hearing' => 'boolean',
            'explained_at' => 'datetime',
            'hearing_at' => 'datetime',
            'decision' => NteOutcome::class,
            'suspension_from' => 'date',
            'decided_at' => 'datetime',
            'decision_acknowledged_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<MeritRecord, $this>
     */
    public function demerit(): BelongsTo
    {
        return $this->belongsTo(MeritRecord::class, 'merit_record_id');
    }

    /**
     * @param  Builder<NoticeToExplain>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', '!=', self::DECIDED);
    }

    public function isDecided(): bool
    {
        return $this->status === self::DECIDED;
    }

    /** Still within the days given to explain. */
    public function canStillExplain(): bool
    {
        return $this->status === self::ISSUED && ! CarbonImmutable::today()->isAfter($this->explain_by);
    }

    /**
     * Due process done: the explanation is in, or the days to give it have passed.
     * Only then can it be decided.
     */
    public function readyToDecide(): bool
    {
        return $this->status === self::EXPLAINED
            || ($this->status === self::ISSUED && CarbonImmutable::today()->isAfter($this->explain_by));
    }

    /** Where it stands, in words, for lists. */
    public function stage(): string
    {
        return match (true) {
            $this->isDecided() => $this->decision?->label() ?? 'Decided',
            $this->hearing_at !== null && $this->hearing_at->isFuture() => 'Hearing '.$this->hearing_at->format('M j, g:i A'),
            $this->status === self::EXPLAINED => 'Explained · to decide',
            $this->readyToDecide() => 'No explanation · to decide',
            default => 'Waiting for the explanation (until '.$this->explain_by->format('M j').')',
        };
    }

    /**
     * Who may issue one to this person, hear it and decide: whoever supervises
     * them. Guards: Operation HQ, or their region's Satellite Operation. Office
     * staff: HR. HR staff: the President or Vice President. Never yourself.
     */
    public static function canIssue(User $issuer, User $employee): bool
    {
        if ($issuer->id === $employee->id || ! $issuer->can(Ability::IssueNoticesToExplain->value)) {
            return false;
        }

        if ($issuer->hasRole(Role::SuperAdmin)) {
            return true;
        }

        $employee->loadMissing('employeeProfile');

        if ($employee->hasRole(Role::Hr)) {
            return $issuer->hasAnyRole(...Role::leadership());
        }

        if ($employee->employeeProfile->is_office_staff ?? true) {
            return $issuer->hasRole(Role::Hr);
        }

        return $issuer->hasRole(Role::OperationsHq)
            || ($issuer->hasRole(Role::SatelliteOperations) && $issuer->canSeeEmployee($employee));
    }

    /**
     * Who may read one: whoever may issue it, HR (all but HR staff's), the
     * Executive and Super Admin. The employee reads their own on My Notices.
     */
    public static function canView(User $viewer, User $employee): bool
    {
        return $viewer->id === $employee->id
            || self::canIssue($viewer, $employee)
            || $viewer->hasAnyRole(Role::SuperAdmin, ...Role::leadership())
            || ($viewer->hasRole(Role::Hr) && ! $employee->hasRole(Role::Hr));
    }

    /**
     * The list on Notices to Explain: the people the viewer may read one of.
     *
     * @param  Builder<NoticeToExplain>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        if ($viewer->hasAnyRole(Role::SuperAdmin, ...Role::leadership())) {
            return;
        }

        $query->where(function (Builder $visible) use ($viewer) {
            $visible->whereRaw('1 = 0');

            if ($viewer->hasRole(Role::Hr)) {
                $visible->orWhereHas('user', fn (Builder $user) => $user->whereKeyNot($viewer->id)->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('role', Role::Hr->value)));
            }

            if ($viewer->hasRole(Role::OperationsHq)) {
                $visible->orWhereHas('user.employeeProfile', fn (Builder $profile) => $profile->where('is_office_staff', false));
            } elseif ($viewer->hasRole(Role::SatelliteOperations)) {
                $visible->orWhereHas('user.employeeProfile', fn (Builder $profile) => $profile->where('is_office_staff', false)->visibleTo($viewer));
            }
        });
    }
}
