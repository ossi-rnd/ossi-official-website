<?php

namespace App\Queries\People;

use App\Enums\EmploymentStatus;
use App\Enums\Region;
use App\Enums\Role;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The company's structure, as the President or Vice President sees it:
 *
 *   Board of Directors → President → Vice President → Super Admin
 *     → HR, Accounting, Admin, Operation HQ
 *       → Satellite Operation → Regional → Provincial → clients → guards
 *
 * Every box is filled from live data: people by role, and the field side from
 * the clients and the guards deployed to them. A few queries in all, whatever the size.
 *
 * @phpstan-type Box array{title: string, people: Collection<int, User>}
 * @phpstan-type ClientRow array{client: Client, guards: int}
 * @phpstan-type ProvinceRow array{name: string, clients: list<ClientRow>, guards: int}
 * @phpstan-type RegionRow array{region: Region, satellites: Collection<int, User>, provinces: list<ProvinceRow>, clients: int, guards: int}
 */
final class OrganizationQuery
{
    /**
     * @return array{chart: array{board: Box, president: Box, vicePresident: Box, superAdmin: Box, departments: list<Box>, satellite: Box}, field: list<RegionRow>}
     */
    public function handle(): array
    {
        return ['chart' => $this->chart(), 'field' => $this->field()];
    }

    /**
     * @return array{board: Box, president: Box, vicePresident: Box, superAdmin: Box, departments: list<Box>, satellite: Box}
     */
    private function chart(): array
    {
        // Everyone who still works here and holds an office role, once.
        $people = User::query()
            ->notifiable([...Role::leadership(), Role::SuperAdmin, Role::Hr, Role::Accounting, Role::Admin, Role::OperationsHq, Role::SatelliteOperations])
            ->with(['roles', 'employeeProfile'])
            ->orderBy('name')
            ->get();

        $withRole = fn (Role $role) => $people->filter(fn (User $user) => $user->hasRole($role))->values();
        $box = fn (string $title, Collection $who) => ['title' => $title, 'people' => $who];

        // By role: the President and Vice President have no HR record (Role::withoutEmployeeRecord).
        $president = $withRole(Role::President);
        $vicePresident = $withRole(Role::VicePresident);

        return [
            // The Board: the President (a director by law, Revised Corporation Code Sec. 24) and the
            // Vice President, both by role (company decision, 2026-10-21; no per-employee tick).
            'board' => $box('Board of Directors', $president->merge($vicePresident)->unique('id')->sortBy('name')->values()),
            'president' => $box('President', $president),
            'vicePresident' => $box('Vice President', $vicePresident),
            'superAdmin' => $box('Super Admin', $withRole(Role::SuperAdmin)),
            'departments' => [
                $box('HR', $withRole(Role::Hr)),
                $box('Accounting', $withRole(Role::Accounting)),
                $box('Admin', $withRole(Role::Admin)),
                $box('Operation HQ', $withRole(Role::OperationsHq)),
            ],
            'satellite' => $box('Satellite Operation', $withRole(Role::SatelliteOperations)),
        ];
    }

    /**
     * The field side: each region with its Satellite Operation officers, its
     * provinces, their clients, and how many active guards each client has.
     * Regions in the enum's order (NCR, CAR, Region I…), only those with clients.
     *
     * @return list<RegionRow>
     */
    private function field(): array
    {
        $clients = Client::query()
            ->withCount(['employeeProfiles as guards_count' => fn ($profile) => $profile
                ->where('is_office_staff', false)
                ->whereIn('employment_status', EmploymentStatus::active())])
            ->orderBy('province')->orderBy('name')
            ->get()
            ->groupBy(fn (Client $client) => $client->region->value);

        $satellites = User::query()->notifiable([Role::SatelliteOperations])->with('employeeProfile')->orderBy('name')->get();

        $regions = [];

        foreach (Region::cases() as $region) {
            $inRegion = $clients->get($region->value);

            if ($inRegion === null) {
                continue;
            }

            $provinces = [];

            foreach ($inRegion->groupBy('province') as $province => $group) {
                $rows = array_values($group->map(fn (Client $client) => ['client' => $client, 'guards' => (int) $client->getAttribute('guards_count')])->all());
                $provinces[] = ['name' => (string) $province, 'clients' => $rows, 'guards' => array_sum(array_column($rows, 'guards'))];
            }

            $regions[] = [
                'region' => $region,
                'satellites' => $satellites->filter(fn (User $user) => $user->operations_region === $region)->values(),
                'provinces' => $provinces,
                'clients' => $inRegion->count(),
                'guards' => array_sum(array_column($provinces, 'guards')),
            ];
        }

        return $regions;
    }
}
