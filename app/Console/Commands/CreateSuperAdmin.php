<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Accounts that aren't employees here: the Super Admin (a system account), and the
 * President and Vice President (paid outside this payroll). They have no HR record and
 * no employee number (Role::withoutEmployeeRecord), so they can't register like staff:
 * they're created here, from the server console. The first Super Admin, on a fresh
 * database, is how anyone gets in at all.
 */
final class CreateSuperAdmin extends Command
{
    protected $signature = 'app:create-super-admin {email} {name}
        {--role=super-admin : super-admin, president or vice-president}';

    protected $description = 'Create a Super Admin, President or Vice President account (no employee record; prompts for the password)';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $name = (string) $this->argument('name');
        $role = Role::tryFrom((string) $this->option('role'));

        if ($role === null || ! in_array($role, Role::withoutEmployeeRecord(), true)) {
            $this->error('The role is super-admin, president or vice-president. Everyone else is added by HR under Employees and registers.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (12+ characters, mixed case, numbers, symbols)');

        $validator = Validator::make(['email' => $email, 'name' => $name, 'password' => $password], [
            'email' => ['required', 'email', 'unique:users,email', 'unique:employee_profiles,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $name, $password, $role) {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->roles()->create(['role' => $role]);
        });

        $this->info($role === Role::SuperAdmin
            ? "Super Admin {$email} created. Sign in, then add HR staff from Employees and give them roles under Roles & Access."
            : "{$role->label()} {$email} created. They sign in with this email and password; turn on two-factor in Account Settings.");

        return self::SUCCESS;
    }
}
