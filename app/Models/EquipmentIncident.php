<?php

namespace App\Models;

use App\Enums\EquipmentIncidentDecision;
use App\Enums\EquipmentIncidentKind;
use App\Support\Heads;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company item reported damaged or lost. Due process before any charge (DOLE's rule on
 * deductions for loss or damage): the employee is told and may explain in writing within
 * EXPLAIN_DAYS; only then does their head decide whether it was their fault, and charge no
 * more than the actual loss, at most 20% of a week's wages per payslip.
 *
 * @property int $id
 * @property int $equipment_issue_id
 * @property EquipmentIncidentKind $kind
 * @property string $details
 * @property int $reported_by
 * @property CarbonImmutable $explain_by
 * @property string|null $explanation
 * @property CarbonImmutable|null $explained_at
 * @property EquipmentIncidentDecision|null $decision
 * @property string|null $decision_reason
 * @property string|null $loss_amount
 * @property string|null $amount_basis
 * @property string|null $installment
 * @property int|null $employee_deduction_id
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read EquipmentIssue $issue
 * @property-read User $reporter
 * @property-read User|null $decider
 */
class EquipmentIncident extends Model
{
    /** Calendar days to explain, as for a Notice to Explain (NoticeToExplain::MIN_DAYS). */
    public const EXPLAIN_DAYS = 5;

    /** DOLE: a deduction for loss or damage may not exceed 20% of the employee's wages in a week. */
    public const WEEKLY_WAGE_SHARE = 0.20;

    /** The week the cap is figured on: six working days (my assumption; confirm with HR). */
    public const DAYS_PER_WEEK = 6;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EquipmentIncidentKind::class,
            'explain_by' => 'date',
            'explained_at' => 'datetime',
            'decision' => EquipmentIncidentDecision::class,
            'loss_amount' => 'decimal:2',
            'installment' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    /** Their head decides (Heads; the Super Admin as backup). Never the person themselves. */
    public static function canDecide(User $decider, User $employee): bool
    {
        return Heads::isHeadOf($decider, $employee);
    }

    public function canStillExplain(?CarbonImmutable $today = null): bool
    {
        return $this->decision === null && $this->explained_at === null && ($today ?? CarbonImmutable::today())->lte($this->explain_by);
    }

    /** Due process done: they explained, or the days to explain have passed. */
    public function readyToDecide(?CarbonImmutable $today = null): bool
    {
        return $this->decision === null && ($this->explained_at !== null || ($today ?? CarbonImmutable::today())->gt($this->explain_by));
    }

    /**
     * The most a payslip may take for this (DOLE: 20% of a week's wages), from their current
     * daily rate; null when no rate is set yet.
     */
    public static function maxInstallmentFor(User $employee): ?float
    {
        $profile = $employee->loadMissing('employeeProfile')->employeeProfile;
        $rate = $profile === null ? null : EmployeeRate::query()->where('employee_profile_id', $profile->id)
            ->whereDate('effective_from', '<=', CarbonImmutable::today()->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')->value('daily_rate');

        return $rate === null ? null : round((float) $rate * self::DAYS_PER_WEEK * self::WEEKLY_WAGE_SHARE, 2);
    }

    /**
     * The cases this viewer sees: of the people they issue to, and of the people they head.
     *
     * @param  Builder<EquipmentIncident>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->whereHas('issue.user', fn (Builder $users) => $users->where(fn (Builder $who) => $who
            ->where(fn (Builder $handled) => EquipmentIssue::constrainPeopleHandledBy($handled, $viewer))
            ->orWhere(fn (Builder $office) => Heads::constrainOfficeStaffHeadedBy($office, $viewer))));
    }

    /**
     * @param  Builder<EquipmentIncident>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('decision');
    }

    /**
     * @return BelongsTo<EquipmentIssue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(EquipmentIssue::class, 'equipment_issue_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
