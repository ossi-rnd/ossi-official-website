<?php

namespace App\Queries\People;

use App\Models\EmployeeRate;
use Carbon\CarbonInterface;

/**
 * Each employee's daily rate in force on a date, in one query for any
 * number of employees. Employees HR hasn't given a rate yet are missing from
 * the result; payroll then falls back to the company default for their type.
 */
final class RatesInForceQuery
{
    /**
     * @param  list<int>  $profileIds
     * @return array<int, float> keyed by employee_profile_id
     */
    public function handle(array $profileIds, CarbonInterface $date): array
    {
        if ($profileIds === []) {
            return [];
        }

        $rates = [];

        // Ascending, so the latest rate that already started wins.
        EmployeeRate::query()
            ->whereIn('employee_profile_id', $profileIds)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderBy('effective_from')
            ->get(['employee_profile_id', 'daily_rate'])
            ->each(function (EmployeeRate $rate) use (&$rates) {
                $rates[$rate->employee_profile_id] = (float) $rate->daily_rate;
            });

        return $rates;
    }
}
