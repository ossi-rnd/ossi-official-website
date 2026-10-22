<?php

namespace App\Queries\People;

use App\Enums\Region;
use App\Queries\Attendance\TodayRosterQuery;

/**
 * Today's people by where they're deployed: region → province → client, plus
 * office staff and guards not deployed yet. Counted from TodayRosterQuery's
 * rows (already scoped to what the viewer may see), so it costs no query.
 *
 *   people   on payroll there
 *   in       clocked in today (late included)
 *   late     of those, late
 *   notIn    nothing yet: no time in, no leave, no holiday
 *   onLeave  approved leave today
 *
 * @phpstan-import-type RosterRow from TodayRosterQuery
 *
 * @phpstan-type AreaCounts array{people: int, in: int, late: int, notIn: int, onLeave: int}
 * @phpstan-type ClientArea array{id: int, name: string, counts: AreaCounts}
 * @phpstan-type ProvinceArea array{name: string, counts: AreaCounts, clients: list<ClientArea>}
 * @phpstan-type RegionArea array{value: string, label: string, counts: AreaCounts, provinces: list<ProvinceArea>}
 * @phpstan-type Workforce array{regions: list<RegionArea>, office: AreaCounts, undeployed: AreaCounts, total: AreaCounts}
 */
final class WorkforceByAreaQuery
{
    /**
     * @param  list<RosterRow>  $roster
     * @return Workforce
     */
    public function handle(array $roster): array
    {
        /** @var array<string, array<string, array<int, list<RosterRow>>>> $tree region → province → client → rows */
        $tree = [];
        /** @var array<int, string> $clientNames */
        $clientNames = [];
        $office = [];
        $undeployed = [];

        foreach ($roster as $row) {
            if ($row['office']) {
                $office[] = $row;
            } elseif ($row['clientId'] === null || $row['region'] === null) {
                $undeployed[] = $row;
            } else {
                $tree[$row['region']][(string) $row['province']][$row['clientId']][] = $row;
                $clientNames[$row['clientId']] = (string) $row['detachment'];
            }
        }

        $regions = [];

        foreach (Region::cases() as $region) {
            if (! isset($tree[$region->value])) {
                continue;
            }

            $provinces = [];
            $byProvince = $tree[$region->value];
            ksort($byProvince);

            foreach ($byProvince as $province => $byClient) {
                $clients = [];

                foreach ($byClient as $clientId => $rows) {
                    $clients[] = ['id' => $clientId, 'name' => $clientNames[$clientId], 'counts' => self::count($rows)];
                }

                usort($clients, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
                $provinces[] = ['name' => (string) $province, 'counts' => self::count(array_merge(...array_values($byClient))), 'clients' => $clients];
            }

            $regions[] = [
                'value' => $region->value,
                'label' => $region->label(),
                'counts' => self::sum(array_map(fn (array $province) => $province['counts'], $provinces)),
                'provinces' => $provinces,
            ];
        }

        return [
            'regions' => $regions,
            'office' => self::count($office),
            'undeployed' => self::count($undeployed),
            'total' => self::count($roster),
        ];
    }

    /**
     * @param  list<RosterRow>  $rows
     * @return AreaCounts
     */
    private static function count(array $rows): array
    {
        $counts = ['people' => 0, 'in' => 0, 'late' => 0, 'notIn' => 0, 'onLeave' => 0];

        foreach ($rows as $row) {
            $counts['people']++;
            $counts['in'] += $row['clockIn'] !== null ? 1 : 0;
            $counts['late'] += in_array($row['status'], ['late', 'late_and_undertime'], true) ? 1 : 0;
            $counts['notIn'] += $row['status'] === 'pending' ? 1 : 0;
            $counts['onLeave'] += $row['status'] === 'on_leave' ? 1 : 0;
        }

        return $counts;
    }

    /**
     * @param  list<AreaCounts>  $all
     * @return AreaCounts
     */
    private static function sum(array $all): array
    {
        $total = ['people' => 0, 'in' => 0, 'late' => 0, 'notIn' => 0, 'onLeave' => 0];

        foreach ($all as $counts) {
            foreach ($counts as $key => $value) {
                $total[$key] += $value;
            }
        }

        return $total;
    }
}
