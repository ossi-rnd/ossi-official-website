<?php

namespace App\Data;

use App\Enums\HolidayType;

/**
 * The pay rates in force for one payroll period, resolved from config/payroll.php
 * by RateScheduleResolver using the run's START date.
 *
 * Per-tier values are private and read through methods that take the
 * office/field flag, so a caller can't accidentally pay a guard the office rate.
 */
final readonly class RateSchedule
{
    /**
     * @param  array{field: float, office: float}  $dailyRate
     * @param  array{field: float, office: float}  $sss
     * @param  array{field: float, office: float}  $philhealth
     * @param  array{field: float, office: float}  $pagibig
     * @param  array{field: float, office: float}  $withholdingRate
     * @param  array<string, float>  $holidayPremiums  keyed by HolidayType value
     */
    public function __construct(
        public string $effectiveFrom,
        private array $dailyRate,
        private array $sss,
        private array $philhealth,
        private array $pagibig,
        private array $withholdingRate,
        private array $holidayPremiums,
        public float $nightDifferentialRate,
        public float $overtimeMultiplier,
        public int $standardHoursPerDay,
        // From the 2026 schedule on: formulas instead of the fixed amounts above.
        public ?StatutoryRules $statutory = null,
        // The region's daily minimum wage on the run's start date (minimum_wages config).
        public ?float $minimumWage = null,
    ) {}

    public function usesStatutoryFormulas(): bool
    {
        return $this->statutory !== null;
    }

    /**
     * Paid at (or below) the minimum wage: exempt from withholding on basic,
     * overtime, holiday pay and night differential (RA 10963 / RR 11-2018).
     * Without a configured minimum wage, the old rule applies: field staff.
     */
    public function isMinimumWageEarner(bool $isOfficeStaff, ?float $dailyRate = null): bool
    {
        return $this->minimumWage !== null
            ? ($dailyRate ?? $this->dailyRate($isOfficeStaff)) <= $this->minimumWage
            : ! $isOfficeStaff;
    }

    public function dailyRate(bool $isOfficeStaff): float
    {
        return $this->dailyRate[$this->tier($isOfficeStaff)];
    }

    /**
     * daily rate / (8 × 60). Not rounded: rounding happens per line item.
     */
    public function minuteRate(bool $isOfficeStaff): float
    {
        return $this->dailyRate($isOfficeStaff) / ($this->standardHoursPerDay * 60);
    }

    public function sss(bool $isOfficeStaff): float
    {
        return $this->sss[$this->tier($isOfficeStaff)];
    }

    public function philhealth(bool $isOfficeStaff): float
    {
        return $this->philhealth[$this->tier($isOfficeStaff)];
    }

    public function pagibig(bool $isOfficeStaff): float
    {
        return $this->pagibig[$this->tier($isOfficeStaff)];
    }

    public function withholdingRate(bool $isOfficeStaff): float
    {
        return $this->withholdingRate[$this->tier($isOfficeStaff)];
    }

    /**
     * Premium ON TOP of basic pay. An unconfigured type earns nothing rather than throwing.
     */
    public function holidayPremium(HolidayType $type): float
    {
        return $this->holidayPremiums[$type->value] ?? 0.0;
    }

    /**
     * @return 'field'|'office'
     */
    private function tier(bool $isOfficeStaff): string
    {
        return $isOfficeStaff ? 'office' : 'field';
    }
}
