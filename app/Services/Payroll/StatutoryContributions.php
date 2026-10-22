<?php

namespace App\Services\Payroll;

use App\Data\StatutoryRules;

/**
 * The government formulas, and nothing else. Pure: no queries, no config, so
 * each rule is unit tested with plain numbers (tests/Unit/StatutoryContributionsTest).
 *
 * Every amount here is MONTHLY except withholding, which uses the BIR
 * semi-monthly table because payroll runs twice a month.
 */
final class StatutoryContributions
{
    /**
     * SSS: the Monthly Salary Credit is the bracket the month's compensation
     * falls in (₱500 steps: ₱5,250–5,749.99 → ₱5,500), clamped to the min/max.
     *
     * @return array{msc: float, employee: float, employer: float, ec: float}
     */
    public function sss(StatutoryRules $rules, float $monthlyCompensation): array
    {
        $step = $rules->sssMscStep;
        $msc = $step * floor(($monthlyCompensation + $step / 2) / $step);
        $msc = min($rules->sssMscMax, max($rules->sssMscMin, $msc));

        return [
            'msc' => $msc,
            'employee' => round($msc * $rules->sssEmployeeRate, 2),
            'employer' => round($msc * $rules->sssEmployerRate, 2),
            // Employees' Compensation: employer only.
            'ec' => $msc >= $rules->sssEcHighFromMsc ? $rules->sssEcHigh : $rules->sssEcLow,
        ];
    }

    /**
     * PhilHealth: 5% of monthly basic salary between the floor and ceiling,
     * shared equally by employee and employer.
     *
     * @return array{base: float, employee: float, employer: float}
     */
    public function philhealth(StatutoryRules $rules, float $monthlyBasic): array
    {
        $base = min($rules->philhealthCeiling, max($rules->philhealthFloor, $monthlyBasic));
        $premium = round($base * $rules->philhealthRate, 2);
        $employee = round($premium / 2, 2);

        return ['base' => $base, 'employee' => $employee, 'employer' => round($premium - $employee, 2)];
    }

    /**
     * Pag-IBIG: on monthly compensation up to the Maximum Fund Salary;
     * 1% from the employee at ₱1,500 or less, 2% above. Employer 2%.
     *
     * @return array{base: float, employee: float, employer: float}
     */
    public function pagibig(StatutoryRules $rules, float $monthlyCompensation): array
    {
        $base = min($rules->pagibigMaxFundSalary, max(0.0, $monthlyCompensation));
        $employeeRate = $monthlyCompensation <= $rules->pagibigLowThreshold ? $rules->pagibigEmployeeRateLow : $rules->pagibigEmployeeRate;

        return [
            'base' => $base,
            'employee' => round($base * $employeeRate, 2),
            'employer' => round($base * $rules->pagibigEmployerRate, 2),
        ];
    }

    /**
     * BIR withholding on one semi-monthly cutoff's taxable compensation
     * (gross taxable pay minus the employee's SSS, PhilHealth and Pag-IBIG):
     * the bracket's fixed amount plus its rate on the excess.
     */
    public function withholdingSemiMonthly(StatutoryRules $rules, float $taxable): float
    {
        $bracket = $this->bracketFor($rules, $taxable);

        return $bracket === null ? 0.0 : round(max(0.0, $bracket['base'] + ($taxable - $bracket['over']) * $bracket['rate']), 2);
    }

    /**
     * The bracket the taxable amount falls in, for showing the working.
     *
     * @return array{over: float, base: float, rate: float}|null
     */
    public function bracketFor(StatutoryRules $rules, float $taxable): ?array
    {
        $found = null;

        foreach ($rules->withholdingSemiMonthly as $bracket) {
            if ($taxable > $bracket['over']) {
                $found = $bracket;
            }
        }

        return $found;
    }
}
