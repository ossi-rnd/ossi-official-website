<?php

namespace App\Data;

/**
 * Government contribution and withholding rules for one rate schedule.
 * Values come from config/payroll.php; the formulas that use them live in
 * App\Services\Payroll\StatutoryContributions.
 *
 * @phpstan-type TaxBracket array{over: float, base: float, rate: float}
 */
final readonly class StatutoryRules
{
    /**
     * @param  list<TaxBracket>  $withholdingSemiMonthly  ascending by `over`
     */
    public function __construct(
        // SSS (RA 11199): 15% of the Monthly Salary Credit.
        public float $sssEmployeeRate,
        public float $sssEmployerRate,
        public float $sssMscMin,
        public float $sssMscMax,
        public float $sssMscStep,
        public float $sssEcLow,
        public float $sssEcHigh,
        public float $sssEcHighFromMsc,
        // PhilHealth (RA 11223): 5% of monthly basic salary, split equally.
        public float $philhealthRate,
        public float $philhealthFloor,
        public float $philhealthCeiling,
        // Pag-IBIG (RA 9679, HDMF Circular 460).
        public float $pagibigEmployeeRate,
        public float $pagibigEmployeeRateLow,
        public float $pagibigLowThreshold,
        public float $pagibigEmployerRate,
        public float $pagibigMaxFundSalary,
        // BIR (RR 11-2018, rates from 2023): the semi-monthly table.
        public array $withholdingSemiMonthly,
    ) {}
}
