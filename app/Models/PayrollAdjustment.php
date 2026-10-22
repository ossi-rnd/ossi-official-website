<?php

namespace App\Models;

use App\Enums\AdjustmentCategory;
use App\Enums\AdjustmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One correction on one employee's payslip in one run. Nothing is fillable:
 * App\Actions\Payroll\*PayrollAdjustment create, review and remove them.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $user_id
 * @property AdjustmentCategory $category
 * @property string $amount signed: positive adds to pay, negative is deducted
 * @property string $reason
 * @property AdjustmentStatus $status
 * @property int $created_by
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PayrollRun $payrollRun
 * @property-read PayrollRun $run
 * @property-read User $employee
 * @property-read User $creator
 * @property-read User|null $reviewer
 */
class PayrollAdjustment extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'reviewed_by' => null,
        'reviewed_at' => null,
        'review_note' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AdjustmentCategory::class,
            'status' => AdjustmentStatus::class,
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The payslip it's on.
     *
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<PayrollAdjustment>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', AdjustmentStatus::Approved);
    }

    /**
     * @param  Builder<PayrollAdjustment>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', AdjustmentStatus::Pending);
    }

    public function isDeduction(): bool
    {
        return (float) $this->amount < 0;
    }

    /**
     * "Correction: missed Nov 3 clock-in": the line printed on the payslip.
     */
    public function lineLabel(): string
    {
        return $this->category->label().': '.$this->reason;
    }
}
