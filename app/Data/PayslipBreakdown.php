<?php

namespace App\Data;

use App\Models\PayslipRecord;

/**
 * Every line of one payslip. Built either by PayslipCalculator (a live
 * estimate) or from a PayslipRecord (frozen after generation), so both the
 * payroll screen and the employee's page render the same object.
 *
 * base     this cutoff's own base         basis    the MONTHLY amount the formula used
 * monthly  the monthly employee amount     employee/employer/ec  due on THIS payslip
 *
 * @phpstan-type Share array{base: float, basis: float, monthly: float, employee: float, employer: float, ec: float}
 * @phpstan-type Contributions array{final_cutoff: bool, minimum_wage_earner: bool, msc: float, taxable: float, tax_bracket: array{over: float, base: float, rate: float}|null, sss: Share, philhealth: Share, pagibig: Share}
 */
final readonly class PayslipBreakdown
{
    /**
     * Deduction fields hold what was ACTUALLY deducted. When pay can't cover
     * everything, deductions stop at ₱0 net and the rest is listed in
     * $shortfallLines: `carries` = owed on the next payslip (contributions,
     * late, earlier carry-overs); otherwise it stays in a loan's balance.
     *
     * @param  list<array{deduction_id?: int, label: string, type: string, amount: float, remaining_balance: float|null}>  $loanLines
     * @param  list<array{carryover_id: int, label: string, amount: float}>  $carryoverLines
     * @param  list<array{label: string, amount: float, carries: bool}>  $shortfallLines
     * @param  list<array{adjustment_id: int, label: string, amount: float}>  $adjustmentLines  signed, as applied
     * @param  Contributions|array{}  $contributions  2026+ formula schedules: what each agency was DUE this
     *                                                cutoff (employee + employer), the bases, and the month position
     */
    public function __construct(
        public string $rateSchedule,
        public bool $isOfficeStaff,
        public int $daysWorked,
        public int $lateMinutes,
        public int $overtimeMinutes,
        public float $dailyRate,
        public float $basicPay,
        public float $holidayPay,
        public float $overtimePay,
        public float $nightDifferential,
        public float $sss,
        public float $philhealth,
        public float $pagibig,
        public float $withholdingTax,
        public float $lateDeduction,
        public array $loanLines,
        public bool $isGenerated = false,
        public array $carryoverLines = [],
        public array $shortfallLines = [],
        public array $adjustmentLines = [],
        public array $contributions = [],
        // Minutes of night work behind nightDifferential (0 on payslips from before it was saved).
        public int $nightMinutes = 0,
    ) {}

    /**
     * Gross = basic + night diff + overtime + holiday + adjustments that ADD to pay.
     * (Only correction adjustments are taxable; the calculator handles that.)
     */
    public function grossPay(): float
    {
        return round($this->basicPay + $this->nightDifferential + $this->overtimePay + $this->holidayPay + $this->adjustmentEarnings(), 2);
    }

    /**
     * @return list<array{adjustment_id: int, label: string, amount: float}>
     */
    public function earningAdjustments(): array
    {
        return array_values(array_filter($this->adjustmentLines, fn (array $line) => $line['amount'] > 0));
    }

    /**
     * @return list<array{adjustment_id: int, label: string, amount: float}>
     */
    public function deductionAdjustments(): array
    {
        return array_values(array_filter($this->adjustmentLines, fn (array $line) => $line['amount'] < 0));
    }

    public function adjustmentEarnings(): float
    {
        return round(array_sum(array_column($this->earningAdjustments(), 'amount')), 2);
    }

    /**
     * As a positive number, like every other deduction.
     */
    public function adjustmentDeductions(): float
    {
        return round(-array_sum(array_column($this->deductionAdjustments(), 'amount')), 2);
    }

    public function governmentDeductions(): float
    {
        return round($this->sss + $this->philhealth + $this->pagibig + $this->withholdingTax, 2);
    }

    public function loanDeductions(): float
    {
        return round(array_sum(array_column($this->loanLines, 'amount')), 2);
    }

    public function carryoverDeductions(): float
    {
        return round(array_sum(array_column($this->carryoverLines, 'amount')), 2);
    }

    public function totalDeductions(): float
    {
        return round($this->governmentDeductions() + $this->carryoverDeductions() + $this->lateDeduction + $this->adjustmentDeductions() + $this->loanDeductions(), 2);
    }

    /**
     * What couldn't be deducted because the pay ran out.
     */
    public function shortfall(): float
    {
        return round(array_sum(array_column($this->shortfallLines, 'amount')), 2);
    }

    public function netPay(): float
    {
        return round($this->grossPay() - $this->totalDeductions(), 2);
    }

    /**
     * From the rate schedule when it knows the minimum wage; older payslips
     * fall back to the original rule (field staff).
     */
    public function isMinimumWageEarner(): bool
    {
        return $this->contributions['minimum_wage_earner'] ?? ! $this->isOfficeStaff;
    }

    public function usesStatutoryFormulas(): bool
    {
        return $this->contributions !== [];
    }

    /**
     * Columns for payslip_records. Decimals are written as strings to keep them exact.
     *
     * @return array<string, mixed>
     */
    public function toRecordAttributes(): array
    {
        $money = fn (float $amount): string => number_format($amount, 2, '.', '');

        return [
            'rate_schedule' => $this->rateSchedule,
            'is_office_staff' => $this->isOfficeStaff,
            'days_worked' => $this->daysWorked,
            'late_minutes' => $this->lateMinutes,
            'overtime_minutes' => $this->overtimeMinutes,
            'daily_rate' => $money($this->dailyRate),
            'basic_pay' => $money($this->basicPay),
            'holiday_pay' => $money($this->holidayPay),
            'overtime_pay' => $money($this->overtimePay),
            'night_differential' => $money($this->nightDifferential),
            'night_minutes' => $this->nightMinutes,
            'gross_pay' => $money($this->grossPay()),
            'sss' => $money($this->sss),
            'philhealth' => $money($this->philhealth),
            'pagibig' => $money($this->pagibig),
            'withholding_tax' => $money($this->withholdingTax),
            'late_deduction' => $money($this->lateDeduction),
            'loan_deductions' => $money($this->loanDeductions()),
            'loan_lines' => $this->loanLines,
            'carryover_deductions' => $money($this->carryoverDeductions()),
            'carryover_lines' => $this->carryoverLines,
            'shortfall_lines' => $this->shortfallLines,
            'adjustment_earnings' => $money($this->adjustmentEarnings()),
            'adjustment_deductions' => $money($this->adjustmentDeductions()),
            'adjustment_lines' => $this->adjustmentLines,
            'contributions' => $this->contributions === [] ? null : $this->contributions,
            'total_deductions' => $money($this->totalDeductions()),
            'net_pay' => $money($this->netPay()),
        ];
    }

    /**
     * The frozen figures: what was saved at generation, not a recomputation.
     */
    public static function fromRecord(PayslipRecord $record): self
    {
        return new self(
            rateSchedule: $record->rate_schedule,
            isOfficeStaff: $record->is_office_staff,
            daysWorked: $record->days_worked,
            lateMinutes: $record->late_minutes,
            overtimeMinutes: $record->overtime_minutes,
            dailyRate: (float) $record->daily_rate,
            basicPay: (float) $record->basic_pay,
            holidayPay: (float) $record->holiday_pay,
            overtimePay: (float) $record->overtime_pay,
            nightDifferential: (float) $record->night_differential,
            nightMinutes: (int) $record->night_minutes,
            sss: (float) $record->sss,
            philhealth: (float) $record->philhealth,
            pagibig: (float) $record->pagibig,
            withholdingTax: (float) $record->withholding_tax,
            lateDeduction: (float) $record->late_deduction,
            // JSON stores 250.0 as 250, which reads back as an int. Each amount is a
            // float again, so a frozen payslip has the same types as a live one.
            loanLines: array_map(fn (array $line) => [...$line, 'amount' => (float) $line['amount']], $record->loan_lines ?? []),
            isGenerated: true,
            carryoverLines: array_map(fn (array $line) => [...$line, 'amount' => (float) $line['amount']], $record->carryover_lines ?? []),
            shortfallLines: array_map(fn (array $line) => [...$line, 'amount' => (float) $line['amount']], $record->shortfall_lines ?? []),
            adjustmentLines: array_map(fn (array $line) => [...$line, 'amount' => (float) $line['amount']], $record->adjustment_lines ?? []),
            contributions: self::contributionsFromRecord($record->contributions),
        );
    }

    /**
     * @param  array{final_cutoff: bool, minimum_wage_earner: bool, msc: int|float, taxable?: int|float, tax_bracket?: array{over: int|float, base: int|float, rate: int|float}|null, sss: array<string, int|float>, philhealth: array<string, int|float>, pagibig: array<string, int|float>}|null  $saved
     * @return Contributions|array{}
     */
    private static function contributionsFromRecord(?array $saved): array
    {
        if ($saved === null) {
            return [];
        }

        $share = fn (array $values): array => [
            'base' => (float) ($values['base'] ?? 0),
            'basis' => (float) ($values['basis'] ?? 0),
            'monthly' => (float) ($values['monthly'] ?? 0),
            'employee' => (float) ($values['employee'] ?? 0),
            'employer' => (float) ($values['employer'] ?? 0),
            'ec' => (float) ($values['ec'] ?? 0),
        ];
        $bracket = $saved['tax_bracket'] ?? null;

        return [
            'final_cutoff' => (bool) $saved['final_cutoff'],
            'minimum_wage_earner' => (bool) $saved['minimum_wage_earner'],
            'msc' => (float) $saved['msc'],
            'taxable' => (float) ($saved['taxable'] ?? 0),
            'tax_bracket' => is_array($bracket) ? ['over' => (float) $bracket['over'], 'base' => (float) $bracket['base'], 'rate' => (float) $bracket['rate']] : null,
            'sss' => $share($saved['sss']),
            'philhealth' => $share($saved['philhealth']),
            'pagibig' => $share($saved['pagibig']),
        ];
    }
}
