<?php

namespace App\Models;

use App\Enums\PayrollConcernCategory;
use App\Enums\PayrollConcernStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's "Report a problem" on a payslip, and Accounting's answer.
 *
 * @property int $id
 * @property int $user_id
 * @property int $payroll_run_id
 * @property PayrollConcernCategory $category
 * @property string $details
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_mime
 * @property PayrollConcernStatus $status
 * @property string|null $response
 * @property int|null $payroll_adjustment_id
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read PayrollRun $run
 * @property-read PayrollAdjustment|null $adjustment
 * @property-read User|null $resolver
 */
class PayrollConcern extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => PayrollConcernCategory::class,
            'status' => PayrollConcernStatus::class,
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<PayrollAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PayrollAdjustment::class, 'payroll_adjustment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @param  Builder<PayrollConcern>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', PayrollConcernStatus::Open);
    }
}
