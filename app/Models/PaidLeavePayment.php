<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave the law pays, suggested on a payroll run (DetectPaidLeave) for Accounting to
 * accept as a "Paid leave" adjustment (or the SSS maternity benefit advance) or to
 * dismiss with a reason. Nothing is paid without that look: which days count for a
 * guard on a roster is a judgment the app shouldn't make alone.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $user_id
 * @property int $leave_request_id
 * @property int $days the leave days in this cut-off, within what the law gives
 * @property string|null $daily_rate
 * @property string|null $amount days × daily rate; null for maternity (the SSS benefit)
 * @property string $status suggested | accepted | dismissed
 * @property int|null $payroll_adjustment_id
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $dismiss_reason
 * @property CarbonImmutable $created_at
 * @property-read PayrollRun $run
 * @property-read User $user
 * @property-read LeaveRequest $leaveRequest
 * @property-read PayrollAdjustment|null $adjustment
 */
class PaidLeavePayment extends Model
{
    public const SUGGESTED = 'suggested';

    public const ACCEPTED = 'accepted';

    public const DISMISSED = 'dismissed';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::SUGGESTED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'daily_rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /**
     * @return BelongsTo<PayrollAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PayrollAdjustment::class, 'payroll_adjustment_id');
    }

    /**
     * @param  Builder<PaidLeavePayment>  $query
     */
    public function scopeSuggested(Builder $query): void
    {
        $query->where('status', self::SUGGESTED);
    }
}
