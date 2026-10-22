<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deduction a payslip could not cover, owed on the next one. Written only
 * by GeneratePayslip: created from a payslip's shortfall, settled when a
 * later payslip brings it in.
 *
 * @property int $id
 * @property int $user_id
 * @property int $from_payroll_run_id
 * @property string $label
 * @property string $amount
 * @property int|null $settled_in_payroll_run_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PayrollRun $fromRun
 */
class PayrollCarryover extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'settled_in_payroll_run_id' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function fromRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'from_payroll_run_id');
    }

    /**
     * Still owed, and from a run that started before $run (so generating an
     * OLDER run later never pulls in a newer run's shortfall).
     *
     * @param  Builder<PayrollCarryover>  $query
     */
    public function scopeOwedBefore(Builder $query, PayrollRun $run): void
    {
        $query->whereNull('settled_in_payroll_run_id')
            ->whereHas('fromRun', fn (Builder $from) => $from->whereDate('start_date', '<', $run->start_date->toDateString()));
    }
}
