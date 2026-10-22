<?php

namespace App\Services\Payroll;

use App\Data\RateSchedule;
use App\Data\StatutoryRules;
use App\Models\MinimumWage;
use App\Models\StatutoryRuleSet;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Picks the rate schedule in force on a date. Callers pass the run's START
 * date, never now(): recomputing a past period against today's rates gives
 * figures that don't match what was paid.
 */
final class RateScheduleResolver
{
    /** @var array<string, RateSchedule> */
    private array $memo = [];

    public function forDate(CarbonInterface $date): RateSchedule
    {
        $target = $date->toDateString();

        return $this->memo[$target] ??= $this->build($target);
    }

    private function build(string $target): RateSchedule
    {
        /** @var array<string, array<string, mixed>> $schedules */
        $schedules = config('payroll.schedules', []);

        // ISO dates sort correctly as strings; newest first, so the first one already in force wins.
        krsort($schedules, SORT_STRING);

        foreach ($schedules as $effectiveFrom => $rates) {
            if ((string) $effectiveFrom <= $target) {
                return $this->hydrate((string) $effectiveFrom, $rates, $target);
            }
        }

        throw new RuntimeException("No payroll rate schedule is in force on {$target}. Add one to config/payroll.php.");
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    private function hydrate(string $effectiveFrom, array $rates, string $target): RateSchedule
    {
        /** @var array<string, float> $premiums */
        $premiums = config('payroll.holiday_premiums', []);

        $zero = ['field' => 0, 'office' => 0];
        $rulesArray = $this->statutoryRulesFor($target, $rates);
        $statutory = $rulesArray !== null ? $this->statutory($rulesArray) : null;

        return new RateSchedule(
            effectiveFrom: $effectiveFrom,
            dailyRate: $this->tiers($rates['daily_rate']),
            // Formula schedules have no fixed amounts; the calculator ignores these then.
            sss: $this->tiers($rates['sss'] ?? $zero),
            philhealth: $this->tiers($rates['philhealth'] ?? $zero),
            pagibig: $this->tiers($rates['pagibig'] ?? $zero),
            withholdingRate: $this->tiers($rates['withholding_rate'] ?? $zero),
            holidayPremiums: array_map('floatval', $premiums),
            nightDifferentialRate: (float) config('payroll.night_differential_rate'),
            overtimeMultiplier: (float) config('payroll.overtime_multiplier'),
            standardHoursPerDay: (int) config('payroll.standard_hours_per_day'),
            statutory: $statutory,
            minimumWage: $statutory !== null ? $this->minimumWage($target) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function statutory(array $rules): StatutoryRules
    {
        /** @var array<string, int|float> $sss */
        $sss = $rules['sss'];
        /** @var array<string, int|float> $philhealth */
        $philhealth = $rules['philhealth'];
        /** @var array<string, int|float> $pagibig */
        $pagibig = $rules['pagibig'];
        /** @var list<array{over: int|float, base: int|float, rate: int|float}> $brackets */
        $brackets = $rules['withholding_semi_monthly'];

        usort($brackets, fn (array $a, array $b) => $a['over'] <=> $b['over']);

        return new StatutoryRules(
            sssEmployeeRate: (float) $sss['employee_rate'],
            sssEmployerRate: (float) $sss['employer_rate'],
            sssMscMin: (float) $sss['msc_min'],
            sssMscMax: (float) $sss['msc_max'],
            sssMscStep: (float) $sss['msc_step'],
            sssEcLow: (float) $sss['ec_low'],
            sssEcHigh: (float) $sss['ec_high'],
            sssEcHighFromMsc: (float) $sss['ec_high_from_msc'],
            philhealthRate: (float) $philhealth['rate'],
            philhealthFloor: (float) $philhealth['floor'],
            philhealthCeiling: (float) $philhealth['ceiling'],
            pagibigEmployeeRate: (float) $pagibig['employee_rate'],
            pagibigEmployeeRateLow: (float) $pagibig['employee_rate_low'],
            pagibigLowThreshold: (float) $pagibig['low_threshold'],
            pagibigEmployerRate: (float) $pagibig['employer_rate'],
            pagibigMaxFundSalary: (float) $pagibig['max_fund_salary'],
            withholdingSemiMonthly: array_map(fn (array $bracket) => ['over' => (float) $bracket['over'], 'base' => (float) $bracket['base'], 'rate' => (float) $bracket['rate']], $brackets),
        );
    }

    /**
     * Where the rules for a date come from: Accounting's latest version that
     * has started (Tax & Contributions page), else the config schedule's
     * built-in rules, else none (the old fixed-amount schedules).
     *
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>|null
     */
    private function statutoryRulesFor(string $target, array $schedule): ?array
    {
        $version = $this->versionFor($target);

        if ($version !== null) {
            return $version->rules;
        }

        return isset($schedule['statutory']) && is_array($schedule['statutory']) ? $schedule['statutory'] : null;
    }

    public function versionFor(string $target): ?StatutoryRuleSet
    {
        return StatutoryRuleSet::query()->whereDate('effective_from', '<=', $target)->orderByDesc('effective_from')->first();
    }

    /**
     * Every minimum wage step: config's built-in list, overridden and extended
     * by what Accounting saved. Oldest first.
     *
     * @return array<string, array{amount: float, wage_order: string|null, saved: bool}> keyed by Y-m-d
     */
    public function minimumWages(): array
    {
        $steps = [];

        /** @var array<string, int|float|array{amount: int|float, wage_order?: string}> $configured */
        $configured = config('payroll.minimum_wages', []);
        foreach ($configured as $from => $step) {
            $steps[(string) $from] = is_array($step)
                ? ['amount' => (float) $step['amount'], 'wage_order' => $step['wage_order'] ?? null, 'saved' => false]
                : ['amount' => (float) $step, 'wage_order' => null, 'saved' => false];
        }

        foreach (MinimumWage::query()->get() as $wage) {
            $steps[$wage->effective_from->toDateString()] = ['amount' => (float) $wage->daily_amount, 'wage_order' => $wage->wage_order, 'saved' => true];
        }

        ksort($steps, SORT_STRING);

        return $steps;
    }

    /**
     * The minimum wage in force on the date, or null when none is known.
     */
    private function minimumWage(string $target): ?float
    {
        $found = null;

        foreach ($this->minimumWages() as $from => $step) {
            if ($from <= $target) {
                $found = $step['amount'];
            }
        }

        return $found;
    }

    /**
     * @return array{field: float, office: float}
     */
    private function tiers(mixed $value): array
    {
        /** @var array{field: int|float, office: int|float} $value */
        return ['field' => (float) $value['field'], 'office' => (float) $value['office']];
    }
}
