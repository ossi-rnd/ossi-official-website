<?php

namespace App\Models;

use App\Enums\Position;
use App\Enums\ReferralStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone an employee referred for hiring (hiring perks). HR links the record of the
 * person once hired; the bonus and the days they must stay are frozen then, from the
 * terms in force (Referral::terms). The referrer is paid on their payslip once the hire
 * has stayed that long (PayDueReferralBonuses). Nothing is fillable: the Actions set it.
 *
 * @property int $id
 * @property int $referrer_id
 * @property string $applicant_name
 * @property string $applicant_phone
 * @property Position|null $position
 * @property string|null $note
 * @property ReferralStatus $status
 * @property int|null $employee_profile_id the hire's record, once hired
 * @property string|null $bonus_amount
 * @property CarbonImmutable|null $bonus_due_on
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int|null $payroll_adjustment_id
 * @property int|null $paid_in_run_id
 * @property CarbonImmutable $created_at
 * @property-read User $referrer
 * @property-read EmployeeProfile|null $hire
 * @property-read User|null $decider
 * @property-read PayrollRun|null $paidInRun
 */
class Referral extends Model
{
    /** The "referrals" Setting HR edits (SaveReferralTerms). */
    public const SETTING = 'referrals';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'submitted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => Position::class,
            'status' => ReferralStatus::class,
            'bonus_amount' => 'decimal:2',
            'bonus_due_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * The terms in force: what HR set on the Referrals page, else the starting values
     * (config hr.referrals). Company policy, not law.
     *
     * @return array{bonus_amount: int, days_to_stay: int}
     */
    public static function terms(): array
    {
        $saved = Setting::value(self::SETTING);
        $start = (array) config('hr.referrals');
        $pick = fn (string $key) => (int) ($saved[$key] ?? $start[$key] ?? 0);

        return ['bonus_amount' => $pick('bonus_amount'), 'days_to_stay' => $pick('days_to_stay')];
    }

    /** Anyone still working here and paid through this payroll may refer someone. */
    public static function canRefer(User $user): bool
    {
        $user->loadMissing('employeeProfile');

        return $user->employeeProfile !== null && $user->employeeProfile->employment_status->isActive() && ! $user->isPaidOutsidePayroll();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function hire(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function paidInRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'paid_in_run_id');
    }

    /**
     * Still open: with HR, or hired and the bonus not settled yet.
     *
     * @param  Builder<Referral>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [ReferralStatus::Submitted, ReferralStatus::Hired]);
    }

    /**
     * Whether the hire stayed until the bonus was due: still working here, or their last
     * day (the resignation that closed their account) on or after the due date. Null while
     * it isn't due yet.
     */
    public function hireStayed(CarbonImmutable $asOf): ?bool
    {
        if ($this->bonus_due_on === null || $this->hire === null || $this->bonus_due_on->isAfter($asOf)) {
            return null;
        }

        if ($this->hire->employment_status->isActive()) {
            return true;
        }

        // Their last day, from the resignation that closed the account.
        $lastDay = $this->hire->user_id === null ? null : Resignation::query()->where('user_id', $this->hire->user_id)
            ->whereNotNull('separated_at')->latest('id')->value('last_day');

        return $lastDay !== null && ! CarbonImmutable::parse($lastDay)->isBefore($this->bonus_due_on);
    }
}
