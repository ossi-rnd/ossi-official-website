<?php

namespace App\Actions\Roles;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\Region;
use App\Enums\Role;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The Super Admin sets the role of someone HR added who hasn't registered yet. Roles
 * belong to accounts, so it waits on the record (pending_role) and ClaimEmployeeAccount
 * gives it to the account when they register. Null clears it. Same rules as
 * SyncUserRoles: a Satellite needs its region, and Super Admin / President / VP never
 * go to someone with an employee record. Audited.
 */
final class SetPendingRole
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(EmployeeProfile $profile, ?Role $role, ?Region $region, User $actor): void
    {
        if (! $actor->can(Ability::ConfigureSystem->value)) {
            throw new AuthorizationException;
        }

        if ($profile->isClaimed()) {
            throw ValidationException::withMessages(['roles' => "{$profile->name} has registered: change the role on their account above."]);
        }

        if ($role === Role::Employee) {
            $role = null; // automatic for everyone with a record
        }

        if ($role !== null && in_array($role, Role::withoutEmployeeRecord(), true)) {
            throw ValidationException::withMessages(['roles' => "{$role->label()} can't go to someone with an employee record: those accounts are outside payroll."]);
        }

        $region = $role === Role::SatelliteOperations ? $region : null;

        if ($role === Role::SatelliteOperations && $region === null) {
            throw ValidationException::withMessages(['region' => 'Choose the region this Satellite Operation covers.']);
        }

        $old = ['role' => $profile->pending_role?->value, 'region' => $profile->pending_region?->value];
        $new = ['role' => $role?->value, 'region' => $region?->value];

        if ($old === $new) {
            return;
        }

        $profile->forceFill(['pending_role' => $role, 'pending_region' => $region])->save();

        $this->audit->record(AuditArea::Roles, 'roles.pending_set', "Set the role {$profile->name} gets on registering: ".($role?->label() ?? 'none'), $profile,
            ['old' => $old, 'new' => $new], causer: $actor);
    }
}
