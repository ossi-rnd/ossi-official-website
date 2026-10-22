<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\ReimbursementCategory;
use App\Enums\ReimbursementStatus;
use App\Support\Acknowledgements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money an office staff member spent for the company, with the receipt (roadmap
 * 25). The President or Vice President approves it; the next payslip pays it back as a
 * non-taxable Reimbursement line (PayApprovedReimbursements).
 *
 * A deployment claim (roadmap 5) is one Operations files for a guard who paid for a
 * deployment: user_id is the guard (paid back on their payslip), filed_by is Operations.
 *
 * @property int $id
 * @property int $user_id who's paid back
 * @property int|null $filed_by Operations, for a guard's deployment claim; null when they filed it themselves
 * @property string|null $deployment_for which deployment (the post, the relief)
 * @property int|null $absence_notice_id the relief a reliever's fare claim came from (one standing claim per relief)
 * @property ReimbursementCategory $category
 * @property CarbonImmutable $spent_on
 * @property string $amount
 * @property string $purpose
 * @property int|null $away_log_id
 * @property string $receipt_path
 * @property string|null $receipt_name
 * @property string $receipt_mime
 * @property ReimbursementStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int|null $payroll_adjustment_id
 * @property int|null $paid_in_run_id
 * @property list<string>|null $acknowledgement what they certified before sending, word for word
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read User|null $decider
 * @property-read User|null $filer
 * @property-read AbsenceNotice|null $absenceNotice
 * @property-read AwayLog|null $awayLog
 * @property-read PayrollRun|null $paidInRun
 */
class Reimbursement extends Model
{
    /** The "reimbursements" Setting Accounting edits (SaveReimbursementLimits). */
    public const SETTING = 'reimbursements';

    /**
     * The limits in force: what Accounting set on the Reimbursements page, else the
     * starting values (config payroll.reimbursements). Company policy, not law.
     *
     * @return array{file_within_days: int, max_amount: int, deployment_max_amount: int}
     */
    public static function limits(): array
    {
        $saved = Setting::value(self::SETTING);
        $start = (array) config('payroll.reimbursements');
        $pick = fn (string $key) => (int) ($saved[$key] ?? $start[$key] ?? 0);

        return ['file_within_days' => $pick('file_within_days'), 'max_amount' => $pick('max_amount'), 'deployment_max_amount' => $pick('deployment_max_amount')];
    }

    /** Receipts older than this many days aren't accepted: file it while it's fresh. */
    public static function fileWithinDays(): int
    {
        return self::limits()['file_within_days'];
    }

    /**
     * The most one claim can be, in pesos; larger purchases go through Accounting.
     * A guard's deployment claim (filed by Operations) has its own, usually lower, cap.
     */
    public static function maxAmount(bool $deployment = false): int
    {
        return self::limits()[$deployment ? 'deployment_max_amount' : 'max_amount'];
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
            'category' => ReimbursementCategory::class,
            'spent_on' => 'date',
            'amount' => 'decimal:2',
            'status' => ReimbursementStatus::class,
            'decided_at' => 'datetime',
            'acknowledgement' => 'array',
            'acknowledged_at' => 'datetime',
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
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    /**
     * @return BelongsTo<AbsenceNotice, $this>
     */
    public function absenceNotice(): BelongsTo
    {
        return $this->belongsTo(AbsenceNotice::class);
    }

    /** Filed by Operations for a guard (a deployment claim), not by the person paid back. */
    public function filedForThem(): bool
    {
        return $this->filed_by !== null && $this->filed_by !== $this->user_id;
    }

    /** Who answers for it: Operations for a deployment claim, else the employee. */
    public function filedByUserId(): int
    {
        return $this->filed_by ?? $this->user_id;
    }

    /**
     * @return BelongsTo<AwayLog, $this>
     */
    public function awayLog(): BelongsTo
    {
        return $this->belongsTo(AwayLog::class);
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function paidInRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'paid_in_run_id');
    }

    /**
     * @param  Builder<Reimbursement>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ReimbursementStatus::Pending);
    }

    /**
     * Approved, waiting for a payslip.
     *
     * @param  Builder<Reimbursement>  $query
     */
    public function scopeToPay(Builder $query): void
    {
        $query->where('status', ReimbursementStatus::Approved)->whereNull('payroll_adjustment_id');
    }

    /**
     * What the employee certifies before sending a claim: the usual expense
     * certification, in plain words.
     *
     * @return list<string>
     */
    public static function acknowledgementLines(string $locale = 'en', bool $forGuard = false): array
    {
        // Filing for a guard, Operations certifies the deployment it ordered or confirmed.
        return array_map(fn (string $key) => Acknowledgements::line('reimbursement.'.$key, $locale), $forGuard ? ['for_guard_genuine', 'once', 'false_claim'] : ['genuine', 'once', 'false_claim']);
    }

    /**
     * Operations filing a guard's deployment cost: Operation HQ for any guard, a
     * Satellite only for its region's (canSeeEmployee); an active guard on this payroll.
     */
    public static function canFileFor(User $filer, User $guard): bool
    {
        $guard->loadMissing('employeeProfile');

        return $filer->can(Ability::FileDeploymentClaims->value)
            && $filer->id !== $guard->id
            && $guard->employeeProfile !== null
            && ! $guard->employeeProfile->is_office_staff
            && $guard->employeeProfile->employment_status->isActive()
            && $filer->canSeeEmployee($guard);
    }

    /** Someone with a claim to see: they can file one, or one was filed for them. */
    public static function canSeeOwn(User $user): bool
    {
        return self::canFile($user) || self::query()->where('user_id', $user->id)->exists();
    }

    /** Office staff on this payroll only (not guards, not the President or Vice President). */
    public static function canFile(User $user): bool
    {
        $user->loadMissing('employeeProfile');

        return $user->employeeProfile !== null
            && (bool) $user->employeeProfile->is_office_staff
            && $user->employeeProfile->employment_status->isActive()
            && ! $user->isPaidOutsidePayroll();
    }
}
