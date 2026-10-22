<?php

namespace App\Actions\Roles;

use App\Enums\AuditArea;
use App\Enums\Region;
use App\Enums\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces a user's roles. Two guard rails stop the system locking itself out:
 * you can't remove your own Super Admin role, and the last Super Admin stays.
 *
 * A Satellite Operation must cover a region; without that role the region is
 * cleared, so a stale one can't limit anybody later.
 */
final class SyncUserRoles
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<Role>  $roles
     */
    public function handle(User $target, array $roles, User $actor, ?Region $region = null): void
    {
        $new = array_values(array_unique(array_map(fn (Role $role) => $role->value, $roles)));
        $old = array_map(fn (Role $role) => $role->value, $target->roleList());

        sort($new);
        sort($old);

        $region = in_array(Role::SatelliteOperations->value, $new, true) ? $region : null;

        if (in_array(Role::SatelliteOperations->value, $new, true) && $region === null) {
            throw ValidationException::withMessages(['region' => 'Choose the region this Satellite Operation covers.']);
        }

        $oldRegion = $target->operations_region;

        if ($new === $old && $oldRegion === $region) {
            return;
        }

        // Super Admin, President and Vice President are accounts without an HR record: an
        // employee on payroll who also needs one of them gets a second, separate account.
        $noRecord = array_map(fn (Role $role) => $role->value, Role::withoutEmployeeRecord());
        $adding = array_intersect(array_diff($new, $old), $noRecord);

        if ($adding !== [] && $target->employeeProfile()->exists()) {
            $names = implode(', ', array_map(fn (string $value) => Role::from($value)->label(), $adding));
            throw ValidationException::withMessages(['roles' => "{$names} can't go to someone with an employee record: those accounts are outside payroll. Create a separate account for that role (php artisan app:create-super-admin)."]);
        }

        $losesSuperAdmin = in_array(Role::SuperAdmin->value, $old, true) && ! in_array(Role::SuperAdmin->value, $new, true);

        if ($losesSuperAdmin && $target->is($actor)) {
            throw ValidationException::withMessages(['roles' => 'You cannot remove your own Super Admin role.']);
        }

        DB::transaction(function () use ($target, $new, $old, $region, $oldRegion, $losesSuperAdmin, $actor) {
            if ($losesSuperAdmin) {
                $superAdmins = UserRole::query()->where('role', Role::SuperAdmin)->lockForUpdate()->count();

                if ($superAdmins <= 1) {
                    throw ValidationException::withMessages(['roles' => 'At least one Super Admin must remain.']);
                }
            }

            $target->roles()->whereNotIn('role', $new)->delete();

            foreach (array_diff($new, $old) as $role) {
                $target->roles()->create(['role' => $role]);
            }

            $target->forceFill(['operations_region' => $region])->save();
            $target->unsetRelation('roles');

            $properties = ['old' => ['roles' => $old], 'new' => ['roles' => $new]];

            if ($oldRegion !== $region) {
                $properties['old']['region'] = $oldRegion?->value;
                $properties['new']['region'] = $region?->value;
            }

            $this->audit->record(AuditArea::Roles, 'roles.changed', "Changed roles of {$target->name}", $target, $properties, causer: $actor);

            // New menus appear (or go) on their next click: tell them why.
            $jobRoles = array_values(array_filter($new, fn (string $role) => $role !== Role::Employee->value));
            UpdateNotice::tell($target, $actor, 'Your access changed',
                ($jobRoles === [] ? 'You have the self-service pages only.' : 'Your role: '.implode(', ', array_map(fn (string $role) => Role::from($role)->label(), $jobRoles)).($region !== null ? " ({$region->label()})" : '').'. Its pages are in your menu.'),
                route('home'), 'shield', email: true);
        });
    }
}
