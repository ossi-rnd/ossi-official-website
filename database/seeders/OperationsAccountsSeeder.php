<?php

namespace Database\Seeders;

use App\Enums\Position;
use App\Enums\Region;
use App\Enums\Role;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo accounts for the President, Vice President, Operation HQ and Satellite Operation
 * (password "password"). Operation HQ and the Satellite get an office HR record so payroll pays
 * them; the President and VP have none (Role::withoutEmployeeRecord). Also a few guards deployed
 * in Region IV-A, so the Satellite account has a region to look after.
 *
 * Safe on a database that already has data (`php artisan db:seed
 * --class=OperationsAccountsSeeder`): existing accounts are left alone, and
 * guards are only moved when nobody is deployed in Region IV-A yet.
 */
class OperationsAccountsSeeder extends Seeder
{
    /** [name, email, role, region, employee number, position]: no number or position for the President and VP */
    private const ACCOUNTS = [
        ['Ramon Lacson', 'president@example.test', Role::President, null, null, null],
        ['Celia Navarro', 'vp@example.test', Role::VicePresident, null, null, null],
        ['Teresa Ilagan', 'opshq@example.test', Role::OperationsHq, null, '170301-OFF-0007', Position::OperationsHead],
        ['Dennis Ocampo', 'satellite@example.test', Role::SatelliteOperations, Region::Calabarzon, '210601-OFF-0008', Position::SatelliteOperationsOfficer],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as [$name, $email, $role, $region, $number, $position]) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = User::factory()->withRoles($role)->create(['name' => $name, 'email' => $email]);
                $user->forceFill(['operations_region' => $region])->save();
            }

            // On payroll like the rest of the office: an HR record with their position.
            // Not the President or VP: those accounts have no HR record (Role::withoutEmployeeRecord).
            if ($number !== null && ! in_array($role, Role::withoutEmployeeRecord(), true) && ! $user->employeeProfile()->exists() && ! EmployeeProfile::query()->where('employee_number', $number)->exists()) {
                EmployeeProfile::factory()->claimedBy($user)->officeStaff()->create(['employee_number' => $number, 'position' => $position]);
            }
        }

        $this->deployInCalabarzon();
    }

    private function deployInCalabarzon(): void
    {
        $sites = Client::query()->where('region', Region::Calabarzon->value)->orderBy('name')->get();

        $alreadyDeployed = EmployeeProfile::query()->whereHas('client', fn ($query) => $query->where('region', Region::Calabarzon->value))->exists();

        if ($sites->isEmpty() || $alreadyDeployed) {
            return;
        }

        // Every fourth active guard, so the NCR sites keep most of them.
        EmployeeProfile::query()
            ->active()
            ->where('is_office_staff', false)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (EmployeeProfile $profile, int $index) => $index % 4 === 3)
            ->values()
            ->each(function (EmployeeProfile $profile, int $index) use ($sites) {
                $site = $sites->skip($index % $sites->count())->firstOrFail();
                $profile->forceFill(['client_id' => $site->id, 'detachment' => $site->name])->save();
            });
    }
}
