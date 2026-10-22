<?php

namespace App\Queries\People;

use App\Models\PositionRate;
use Carbon\CarbonInterface;

/**
 * The company rate of every position on a date, keyed by position value.
 * Positions without a rate yet are missing from the result.
 */
final class PositionRatesInForceQuery
{
    /**
     * @return array<string, float>
     */
    public function handle(CarbonInterface $date): array
    {
        $rates = [];

        // Ascending, so the latest rate that already started wins.
        PositionRate::query()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderBy('effective_from')
            ->get(['position', 'daily_rate'])
            ->each(function (PositionRate $rate) use (&$rates) {
                $rates[$rate->position->value] = (float) $rate->daily_rate;
            });

        return $rates;
    }
}
