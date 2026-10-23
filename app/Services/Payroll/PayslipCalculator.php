<?php

namespace App\Services\Payroll;

use App\Data\AdjustmentLine;
use App\Data\LoanLine;
use App\Data\MonthToDate;
use App\Data\PayslipBreakdown;
use App\Data\PayslipInput;
use App\Data\RateSchedule;
use App\Data\StatutoryRules;
use App\Enums\HolidayType;

/**
 * The one payslip formula, shared by the payroll screen and the employee's
 * own payslip page so they can never disagree. Pure: no queries, no config.
 *
 *   minute rate   = daily rate / (8 × 60)
 *   basic         = daily rate × days worked
 *   night diff    = round(basic × 0.10)                 night-shift employees only
 *   overtime      = round(OT minutes × minute rate × 1.25)
 *   holiday       = Σ holiday days worked × daily rate × premium
 *   taxable gross = basic + night diff + overtime + holiday + correction adjustments
 *   gross         = taxable gross + allowance/refund adjustments (not taxed)
 *   deductions    = SSS + PhilHealth + Pag-IBIG + tax + carried over + late + deduction adjustments + loan installments
 *   net           = gross − deductions, never below ₱0
 *
 * Deductions are taken in exactly that order and stop when the pay runs out.
 * The rest is a shortfall: carried to the next payslip, except an unpaid loan
 * installment, which just stays in the loan's balance.
 *
 * @phpstan-import-type Contributions from PayslipBreakdown
 */
final class PayslipCalculator
{
    public function __construct(private readonly StatutoryContributions $formulas = new StatutoryContributions) {}

    /**
     * What the attendance itself is worth: basic, holiday premium, overtime and night
     * differential, less the late deduction DUE (before the pay runs out). No
     * contributions, loans or adjustments. calculate() is built on it, and
     * DetectPriorPeriodCorrections compares it against a frozen payslip, so a
     * recomputed cut-off can never drift from how payslips are computed.
     *
     * @return array{daily_rate: float, basic: float, holiday: float, overtime: float, night: float, late: float, total: float}
     */
    public function attendancePay(PayslipInput $input, RateSchedule $rates): array
    {
        // The employee's own rate from HR when set; the schedule's default for their type otherwise.
        $dailyRate = $input->dailyRate ?? $rates->dailyRate($input->isOfficeStaff);
        $minuteRate = $dailyRate / ($rates->standardHoursPerDay * 60);

        $basic = $dailyRate * $input->daysWorked;
        // Labor Code Art. 86: 10% of the regular rate for each hour worked 10 PM–6 AM.
        $night = round($input->nightMinutes * $minuteRate * $rates->nightDifferentialRate);
        $overtime = round($input->overtimeMinutes * $minuteRate * $rates->overtimeMultiplier);
        $holiday = $this->holidayPremium($input->holidayDaysWorked, $dailyRate, $rates);
        $late = $this->lateDeduction($input->lateMinutes, $minuteRate);

        return [
            'daily_rate' => $dailyRate,
            'basic' => $basic,
            'holiday' => $holiday,
            'overtime' => $overtime,
            'night' => $night,
            'late' => $late,
            'total' => round(round($basic, 2) + $holiday + $overtime + $night - $late, 2),
        ];
    }

    public function calculate(PayslipInput $input, RateSchedule $rates): PayslipBreakdown
    {
        $office = $input->isOfficeStaff;
        $pay = $this->attendancePay($input, $rates);
        $dailyRate = $pay['daily_rate'];
        $basicPay = $pay['basic'];
        $nightDifferential = $pay['night'];
        $overtimePay = $pay['overtime'];
        $holidayPay = $pay['holiday'];

        // Approved adjustments that ADD to pay. Corrections replace pay that was
        // earned, so they are taxed like it; allowances and refunds are not.
        $earningAdjustments = array_values(array_filter($input->adjustments, fn (AdjustmentLine $line) => $line->amount > 0));
        $taxableAdjustments = array_sum(array_map(fn (AdjustmentLine $line) => $line->isTaxable ? $line->amount : 0.0, $earningAdjustments));
        $otherAdjustments = array_sum(array_map(fn (AdjustmentLine $line) => $line->isTaxable ? 0.0 : $line->amount, $earningAdjustments));

        $taxableGross = $basicPay + $nightDifferential + $overtimePay + $holidayPay + $taxableAdjustments;

        // What is DUE, before checking the pay can cover it.
        $contributions = [];

        if ($rates->statutory !== null) {
            // 2026+: computed from the government rules. No attendance = no payslip = nothing due.
            [$due, $contributions] = $input->daysWorked > 0
                ? $this->statutoryDue($rates->statutory, $input->monthToDate ?? MonthToDate::wholeMonth(), $basicPay, $taxableGross, $rates->isMinimumWageEarner($office, $dailyRate))
                : [['sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'withholding_tax' => 0.0], []];
        } else {
            // Older schedules: the fixed amounts of the original spec.
            $due = [
                'sss' => $rates->sss($office),
                'philhealth' => $rates->philhealth($office),
                'pagibig' => $rates->pagibig($office),
                // 0% for field staff: minimum wage earners are exempt (RA 9504).
                'withholding_tax' => round($taxableGross * $rates->withholdingRate($office), 2),
            ];
        }
        $lateDue = $pay['late'];

        // Net pay never goes below ₱0. Deductions are taken in this order until
        // the pay runs out; whatever is left is listed as a shortfall.
        $remaining = round($taxableGross + $otherAdjustments, 2);
        $shortfalls = [];
        $take = function (float $amount, string $label, bool $carries) use (&$remaining, &$shortfalls): float {
            $taken = round(min($amount, max(0.0, $remaining)), 2);
            $remaining = round($remaining - $taken, 2);

            if ($amount - $taken >= 0.01) {
                $shortfalls[] = ['label' => $label, 'amount' => round($amount - $taken, 2), 'carries' => $carries];
            }

            return $taken;
        };

        // 1. Statutory contributions and tax: owed to the government first.
        $sss = $take($due['sss'], 'SSS', true);
        $philhealth = $take($due['philhealth'], 'PhilHealth', true);
        $pagibig = $take($due['pagibig'], 'Pag-IBIG', true);
        $withholdingTax = $take($due['withholding_tax'], 'Withholding tax', true);

        // 2. What earlier payslips couldn't cover.
        $carryoverLines = [];
        foreach ($input->carryovers as $carryover) {
            $carryoverLines[] = ['carryover_id' => $carryover->carryoverId, 'label' => $carryover->label, 'amount' => $take($carryover->amount, $carryover->label, true)];
        }

        // 3. Late deduction, 4. deduction adjustments, then 5. loans. An unpaid
        // loan installment is NOT carried: it simply stays in the loan's balance.
        $lateDeduction = $take($lateDue, 'Late deduction', true);

        $adjustmentLines = array_map(fn (AdjustmentLine $line) => ['adjustment_id' => $line->adjustmentId, 'label' => $line->label, 'amount' => round($line->amount, 2)], $earningAdjustments);
        foreach ($input->adjustments as $line) {
            if ($line->amount < 0) {
                $adjustmentLines[] = ['adjustment_id' => $line->adjustmentId, 'label' => $line->label, 'amount' => -$take(-$line->amount, $line->label, true)];
            }
        }

        $loanLines = array_map(fn (LoanLine $loan) => [...$loan->toArray(), 'amount' => $take($loan->amount, $loan->label, false)], $input->loans);

        return new PayslipBreakdown(
            rateSchedule: $rates->effectiveFrom,
            isOfficeStaff: $office,
            daysWorked: $input->daysWorked,
            lateMinutes: $input->lateMinutes,
            overtimeMinutes: $input->overtimeMinutes,
            dailyRate: $dailyRate,
            basicPay: $basicPay,
            holidayPay: $holidayPay,
            overtimePay: $overtimePay,
            nightDifferential: $nightDifferential,
            nightMinutes: $input->nightMinutes,
            sss: $sss,
            philhealth: $philhealth,
            pagibig: $pagibig,
            withholdingTax: $withholdingTax,
            lateDeduction: $lateDeduction,
            loanLines: $loanLines,
            carryoverLines: $carryoverLines,
            shortfallLines: $shortfalls,
            adjustmentLines: $adjustmentLines,
            contributions: $contributions,
        );
    }

    /**
     * SSS, PhilHealth and Pag-IBIG are MONTHLY; payroll runs twice a month.
     *
     *   1st cutoff   half of the monthly amount, estimated as if the month
     *                continues like this cutoff (base × 2)
     *   last cutoff  monthly amount on the ACTUAL month (earlier cutoffs +
     *                this one) minus what the earlier cutoffs were due
     *
     * Bases: SSS uses all taxable compensation; PhilHealth and Pag-IBIG use
     * basic pay. Tax (unless a minimum wage earner) is the BIR semi-monthly
     * table on taxable pay after the employee's three contributions.
     *
     * @return array{0: array{sss: float, philhealth: float, pagibig: float, withholding_tax: float}, 1: Contributions}
     */
    private function statutoryDue(StatutoryRules $rules, MonthToDate $month, float $basicPay, float $taxableGross, bool $minimumWageEarner): array
    {
        $final = $month->isFinalCutoff;
        $scale = $final ? 1.0 : 2.0;
        $part = fn (float $monthly, float $already): float => $final ? round(max(0.0, $monthly - $already), 2) : round($monthly / 2, 2);

        $sssMonthly = $this->formulas->sss($rules, $final ? $month->sss['base'] + $taxableGross : $taxableGross * $scale);
        $philhealthMonthly = $this->formulas->philhealth($rules, $final ? $month->philhealth['base'] + $basicPay : $basicPay * $scale);
        $pagibigMonthly = $this->formulas->pagibig($rules, $final ? $month->pagibig['base'] + $basicPay : $basicPay * $scale);

        // basis = the monthly amount the formula was applied to; monthly = the monthly
        // employee contribution. Saved so any payslip can show its working later.
        $sssBasis = $final ? $month->sss['base'] + $taxableGross : $taxableGross * $scale;
        $philhealthBasis = $final ? $month->philhealth['base'] + $basicPay : $basicPay * $scale;

        $shares = [
            'sss' => ['base' => round($taxableGross, 2), 'basis' => round($sssBasis, 2), 'monthly' => $sssMonthly['employee'], 'employee' => $part($sssMonthly['employee'], $month->sss['employee']), 'employer' => $part($sssMonthly['employer'], $month->sss['employer']), 'ec' => $part($sssMonthly['ec'], $month->sss['ec'])],
            'philhealth' => ['base' => round($basicPay, 2), 'basis' => $philhealthMonthly['base'], 'monthly' => $philhealthMonthly['employee'], 'employee' => $part($philhealthMonthly['employee'], $month->philhealth['employee']), 'employer' => $part($philhealthMonthly['employer'], $month->philhealth['employer']), 'ec' => 0.0],
            'pagibig' => ['base' => round($basicPay, 2), 'basis' => $pagibigMonthly['base'], 'monthly' => $pagibigMonthly['employee'], 'employee' => $part($pagibigMonthly['employee'], $month->pagibig['employee']), 'employer' => $part($pagibigMonthly['employer'], $month->pagibig['employer']), 'ec' => 0.0],
        ];

        $employeeContributions = $shares['sss']['employee'] + $shares['philhealth']['employee'] + $shares['pagibig']['employee'];
        $taxable = round(max(0.0, $taxableGross - $employeeContributions), 2);
        $tax = $minimumWageEarner ? 0.0 : $this->formulas->withholdingSemiMonthly($rules, $taxable);

        return [
            ['sss' => $shares['sss']['employee'], 'philhealth' => $shares['philhealth']['employee'], 'pagibig' => $shares['pagibig']['employee'], 'withholding_tax' => $tax],
            [
                'final_cutoff' => $final,
                'minimum_wage_earner' => $minimumWageEarner,
                'msc' => $sssMonthly['msc'],
                'taxable' => $taxable,
                'tax_bracket' => $minimumWageEarner ? null : $this->formulas->bracketFor($rules, $taxable),
                ...$shares,
            ],
        ];
    }

    /**
     * Only the PREMIUM: basic pay already covers the day, so a worked regular
     * holiday totals 200% and a special day 130%. Unworked holidays earn nothing.
     *
     * @param  array<string, int>  $daysByType
     */
    private function holidayPremium(array $daysByType, float $dailyRate, RateSchedule $rates): float
    {
        $premium = 0.0;

        foreach (HolidayType::cases() as $type) {
            $premium += ($daysByType[$type->value] ?? 0) * $dailyRate * $rates->holidayPremium($type);
        }

        return round($premium, 2);
    }

    /**
     * Exactly the time not worked (the owner's choice, 2026-10-23; "no work, no pay"): the minutes
     * late after the grace period × daily rate ÷ 8 hours ÷ 60. Never rounded up to a block of time,
     * which would deduct time actually worked (Labor Code Art. 113). The late-deduction brackets
     * that could override this were removed.
     */
    private function lateDeduction(int $lateMinutes, float $minuteRate): float
    {
        return $lateMinutes <= 0 ? 0.0 : round($lateMinutes * $minuteRate);
    }
}
