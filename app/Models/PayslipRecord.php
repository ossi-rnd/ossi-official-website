<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A frozen payslip. Written once by GeneratePayslip, then only paid_at/paid_by change.
 * Nothing is fillable on purpose.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $user_id
 * @property string $rate_schedule
 * @property bool $is_office_staff
 * @property int $days_worked
 * @property int $late_minutes
 * @property int $overtime_minutes
 * @property string $daily_rate
 * @property string $basic_pay
 * @property string $holiday_pay
 * @property string $overtime_pay
 * @property string $night_differential
 * @property string $gross_pay
 * @property string $sss
 * @property string $philhealth
 * @property string $pagibig
 * @property string $withholding_tax
 * @property string $late_deduction
 * @property string $loan_deductions
 * @property list<array{deduction_id?: int, label: string, type: string, amount: float|int, remaining_balance: float|int|null}>|null $loan_lines
 * @property string $carryover_deductions
 * @property list<array{carryover_id: int, label: string, amount: float|int}>|null $carryover_lines
 * @property list<array{label: string, amount: float|int, carries: bool}>|null $shortfall_lines
 * @property string $adjustment_earnings
 * @property string $adjustment_deductions
 * @property list<array{adjustment_id: int, label: string, amount: float|int}>|null $adjustment_lines
 * @property array{final_cutoff: bool, minimum_wage_earner: bool, msc: int|float, taxable?: int|float, tax_bracket?: array{over: int|float, base: int|float, rate: int|float}|null, sss: array<string, int|float>, philhealth: array<string, int|float>, pagibig: array<string, int|float>}|null $contributions
 * @property string $total_deductions
 * @property string $net_pay
 * @property CarbonImmutable $generated_at
 * @property int|null $generated_by
 * @property CarbonImmutable|null $paid_at
 * @property int|null $paid_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PayrollRun $payrollRun
 * @property-read User $user
 */
class PayslipRecord extends Model
{
    /**
     * Decimal casts come back as strings ("7930.00"): exact, unlike floats.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_office_staff' => 'boolean',
            'days_worked' => 'integer',
            'late_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'daily_rate' => 'decimal:2',
            'basic_pay' => 'decimal:2',
            'holiday_pay' => 'decimal:2',
            'overtime_pay' => 'decimal:2',
            'night_differential' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'sss' => 'decimal:2',
            'philhealth' => 'decimal:2',
            'pagibig' => 'decimal:2',
            'withholding_tax' => 'decimal:2',
            'carryover_deductions' => 'decimal:2',
            'adjustment_earnings' => 'decimal:2',
            'adjustment_deductions' => 'decimal:2',
            'late_deduction' => 'decimal:2',
            'loan_deductions' => 'decimal:2',
            'loan_lines' => 'array',
            'carryover_lines' => 'array',
            'shortfall_lines' => 'array',
            'adjustment_lines' => 'array',
            'contributions' => 'array',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'generated_at' => 'datetime',
            'paid_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
