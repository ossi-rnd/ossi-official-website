<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's 13th month in a run, with the payslips it was computed from.
 *
 * @property int $id
 * @property int $thirteenth_month_run_id
 * @property int $user_id
 * @property int $days_worked
 * @property string $basic_pay
 * @property string $late_deductions
 * @property string $earned
 * @property string $earlier_paid
 * @property string $amount
 * @property list<array{payroll_run_id: int, period: string, basic_pay: float|int, late_deduction: float|int}> $cutoffs
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ThirteenthMonthRun $run
 * @property-read User $user
 */
class ThirteenthMonthPayout extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days_worked' => 'integer',
            'basic_pay' => 'decimal:2',
            'late_deductions' => 'decimal:2',
            'earned' => 'decimal:2',
            'earlier_paid' => 'decimal:2',
            'amount' => 'decimal:2',
            'cutoffs' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ThirteenthMonthRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ThirteenthMonthRun::class, 'thirteenth_month_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
