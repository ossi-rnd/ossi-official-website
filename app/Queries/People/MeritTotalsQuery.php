<?php

namespace App\Queries\People;

use App\Models\MeritRecord;
use Carbon\CarbonImmutable;

/**
 * Each person's merits, demerits and net over the last 12 months (standing
 * records only), in one query for a whole list.
 */
final class MeritTotalsQuery
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, array{merits: int, demerits: int, net: int}> keyed by user id; only people with records
     */
    public function handle(array $userIds, ?CarbonImmutable $today = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $totals = [];

        foreach (MeritRecord::query()->counting($today)->whereIn('user_id', $userIds)
            ->selectRaw('user_id, sum(case when points > 0 then points else 0 end) as merits, sum(case when points < 0 then -points else 0 end) as demerits')
            ->groupBy('user_id')->toBase()->get() as $row) {
            $totals[(int) $row->user_id] = ['merits' => (int) $row->merits, 'demerits' => (int) $row->demerits, 'net' => (int) $row->merits - (int) $row->demerits];
        }

        return $totals;
    }
}
