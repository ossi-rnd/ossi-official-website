<?php

namespace App\Support;

use App\Enums\Region;
use App\Enums\Role;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The owner's chain of heads (2026-10-23): who supervises whom, in one place.
 *
 *   a guard                                         Operations: Operation HQ, and the Satellite of their client's region
 *   HR                                              the President or Vice President
 *   a Satellite Operation                           Operation HQ
 *   other office staff (Admin, Accounting,
 *   Operation HQ, Developer, staff with no role)    HR
 *
 * Used for attendance alerts, deciding office staff's leave and overtime, watching their
 * time away, and Notices to Explain / merits. The Super Admin may act for any head (backup),
 * and the President or Vice President still SEE every office staffer's requests (they monitor).
 */
final class Heads
{
    public const OPERATIONS = 'operations';

    public const LEADERSHIP = 'leadership';

    public const OPERATIONS_HQ = 'operations_hq';

    public const HR = 'hr';

    /** Which group heads this person. */
    public static function groupOf(User $person): string
    {
        $person->loadMissing(['roles', 'employeeProfile']);

        return match (true) {
            $person->employeeProfile !== null && ! $person->employeeProfile->is_office_staff => self::OPERATIONS,
            $person->hasRole(Role::Hr) => self::LEADERSHIP,
            $person->hasRole(Role::SatelliteOperations) => self::OPERATIONS_HQ,
            default => self::HR,
        };
    }

    /** "HR", "Operation HQ", "the President or Vice President", "Operations": for messages. */
    public static function label(User $person): string
    {
        return match (self::groupOf($person)) {
            self::OPERATIONS => 'Operations',
            self::LEADERSHIP => Role::LEADERSHIP_LABEL,
            self::OPERATIONS_HQ => 'Operation HQ',
            default => 'HR',
        };
    }

    /**
     * The people who head this person, still working here; never the person themselves.
     * $client: where a guard actually worked (a relief duty), else their own client.
     *
     * @return Collection<int, User>
     */
    public static function of(User $person, ?Client $client = null): Collection
    {
        $person->loadMissing('employeeProfile.client');

        $heads = match (self::groupOf($person)) {
            self::OPERATIONS => self::operationsFor(($client ?? $person->employeeProfile?->client)?->region),
            self::LEADERSHIP => User::query()->notifiable(Role::leadership())->get(),
            self::OPERATIONS_HQ => User::query()->notifiable([Role::OperationsHq])->get(),
            default => User::query()->notifiable([Role::Hr])->get(),
        };

        return $heads->reject(fn (User $head) => $head->is($person))->values();
    }

    /** Whether $head heads $person (and so may decide for them). The Super Admin may, as backup. */
    public static function isHeadOf(User $head, User $person): bool
    {
        if ($head->is($person)) {
            return false;
        }

        if ($head->hasRole(Role::SuperAdmin)) {
            return true;
        }

        return match (self::groupOf($person)) {
            self::OPERATIONS => $head->hasRole(Role::OperationsHq) || ($head->hasRole(Role::SatelliteOperations) && $head->canSeeEmployee($person)),
            self::LEADERSHIP => $head->hasAnyRole(...Role::leadership()),
            self::OPERATIONS_HQ => $head->hasRole(Role::OperationsHq),
            default => $head->hasRole(Role::Hr),
        };
    }

    /**
     * Narrows a query of users to the OFFICE staff this viewer heads: HR → office staff who aren't
     * HR or a Satellite; Operation HQ → Satellites; the President or VP → HR; Super Admin → all.
     *
     * @param  Builder<User>  $users
     */
    public static function constrainOfficeStaffHeadedBy(Builder $users, User $viewer): void
    {
        $users->officeStaff();
        $role = fn (Role ...$roles) => fn (Builder $userRoles) => $userRoles->whereIn('role', array_map(fn (Role $r) => $r->value, $roles));

        match (true) {
            $viewer->hasRole(Role::SuperAdmin) => null,
            $viewer->hasAnyRole(...Role::leadership()) => $users->whereHas('roles', $role(Role::Hr)),
            $viewer->hasRole(Role::OperationsHq) => $users->whereHas('roles', $role(Role::SatelliteOperations)),
            $viewer->hasRole(Role::Hr) => $users->whereDoesntHave('roles', $role(Role::Hr, Role::SatelliteOperations)),
            default => $users->whereRaw('1 = 0'),
        };
    }

    /** Sees every office staffer's requests and time away: the President or VP (monitor) and the Super Admin. */
    public static function seesAllOfficeStaff(User $viewer): bool
    {
        return $viewer->hasAnyRole(Role::SuperAdmin, ...Role::leadership());
    }

    /** Sees some office staff's requests: everyone who heads office staff (HR, Operation HQ) or monitors them all. */
    public static function headsOfficeStaff(User $viewer): bool
    {
        return self::seesAllOfficeStaff($viewer) || $viewer->hasAnyRole(Role::Hr, Role::OperationsHq);
    }

    /**
     * Operation HQ, and the Satellite Operation covering this region.
     *
     * @return Collection<int, User>
     */
    private static function operationsFor(?Region $region): Collection
    {
        return User::query()->notifiable([Role::OperationsHq, Role::SatelliteOperations])->with('roles')->get()
            ->filter(fn (User $user) => $user->hasRole(Role::OperationsHq) || ($region !== null && $user->operations_region === $region))
            ->values();
    }
}
