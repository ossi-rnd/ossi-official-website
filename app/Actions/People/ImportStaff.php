<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Enums\EmploymentStatus;
use App\Enums\Position;
use App\Enums\Role;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EmployeeNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Sets up the office accounts on a fresh system from a private list, instead
 * of adding and approving each one by hand (app:import-staff).
 *
 * Every row is checked first; if any is wrong, nothing is created. Each
 * new person gets an HR record, an account with a random password nobody
 * knows, the Employee role plus the listed role(s), and a "set your
 * password" email. People who already exist are skipped, so a second run
 * is harmless.
 */
final class ImportStaff
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array<string, string>>  $rows  name, email, employee_number, role, and optionally job, hire_date, status
     * @return array{created: list<string>, skipped: list<string>, errors: list<string>}
     */
    public function handle(array $rows, bool $sendLinks = true): array
    {
        $errors = [];
        $parsed = [];
        $seenEmails = [];
        $seenNumbers = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;   // the header is line 1
            [$person, $rowErrors] = $this->parse($row);

            if (isset($seenEmails[$person['email']]) || isset($seenNumbers[$person['employee_number']])) {
                $rowErrors[] = 'appears twice in the file';
            }

            $seenEmails[$person['email']] = true;
            $seenNumbers[$person['employee_number']] = true;

            foreach ($rowErrors as $error) {
                $errors[] = "Line {$line} ({$person['email']}): {$error}";
            }

            $parsed[] = $person;
        }

        if ($errors !== []) {
            return ['created' => [], 'skipped' => [], 'errors' => $errors];
        }

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($parsed, &$created, &$skipped) {
            foreach ($parsed as $person) {
                $exists = User::query()->where('email', $person['email'])->exists()
                    || EmployeeProfile::query()->where('email', $person['email'])->orWhere('employee_number', $person['employee_number'])->exists();

                if ($exists) {
                    $skipped[] = "{$person['name']} <{$person['email']}>: already exists";

                    continue;
                }

                $user = User::create(['name' => $person['name'], 'email' => $person['email'], 'password' => Str::password(40)]);
                // The set-password link goes to this address, so opening it proves the inbox.
                $user->forceFill(['email_verified_at' => now()])->save();

                foreach ([Role::Employee, ...$person['roles']] as $role) {
                    $user->roles()->create(['role' => $role]);
                }

                $profile = new EmployeeProfile;
                $profile->forceFill([
                    'employee_number' => $person['employee_number'],
                    'name' => $person['name'],
                    'email' => $person['email'],
                    'is_office_staff' => $person['position']?->isOffice() ?? true,
                    'position' => $person['position'],
                    'hire_date' => $person['hire_date'],
                    'employment_status' => $person['status'],
                ]);
                $profile->user()->associate($user);
                $profile->save();

                $this->audit->record(AuditArea::People, 'staff.imported', "Imported {$person['name']} as ".implode(', ', array_map(fn (Role $role) => $role->label(), $person['roles'])), $profile, [
                    'employee_number' => $person['employee_number'],
                    'email' => $person['email'],
                    'roles' => array_map(fn (Role $role) => $role->value, $person['roles']),
                ]);

                $created[] = $person['email'];
            }
        });

        // After the commit: an email must never point at an account that was rolled back.
        if ($sendLinks) {
            foreach ($created as $email) {
                Password::broker()->sendResetLink(['email' => $email]);
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => []];
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: array{name: string, email: string, employee_number: string, roles: list<Role>, position: Position|null, hire_date: string|null, status: EmploymentStatus}, 1: list<string>}
     */
    private function parse(array $row): array
    {
        $value = fn (string $key): string => trim($row[$key] ?? '');
        $errors = array_values(Validator::make(
            ['name' => $value('name'), 'email' => $value('email'), 'employee_number' => $value('employee_number'), 'hire_date' => $value('hire_date') ?: null],
            ['name' => ['required', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'employee_number' => ['required', 'max:50'], 'hire_date' => ['nullable', 'date']],
        )->errors()->all());

        if ($value('employee_number') !== '' && ! EmployeeNumber::isValid(EmployeeNumber::normalize($value('employee_number')))) {
            $errors[] = 'employee number "'.$value('employee_number').'": '.lcfirst(EmployeeNumber::HELP);
        }

        $roles = [];
        foreach (preg_split('/\s*[|;]\s*/', $value('role')) ?: [] as $name) {
            if ($name === '') {
                continue;
            }

            $role = $this->match(Role::cases(), $name, fn (Role $role) => $role->label());
            $role === null || $role === Role::Employee
                ? $errors[] = "unknown role \"{$name}\" (use ".implode(', ', array_map(fn (Role $role) => $role->label(), array_filter(Role::cases(), fn (Role $role) => $role !== Role::Employee))).')'
                : $roles[] = $role;
        }

        if ($roles === []) {
            $errors[] = 'needs a role';
        }

        $position = null;
        if ($value('job') !== '') {
            // Only jobs that can be given today: not a retired one (Security Manager, President…).
            $position = $this->match(Position::offered(), $value('job'), fn (Position $position) => $position->label());
            if ($position === null) {
                $errors[] = "unknown job \"{$value('job')}\" (use ".implode(', ', array_map(fn (Position $position) => $position->label(), Position::offered())).')';
            }
        }

        // Office staff being set up are usually already regular.
        $status = $value('status') === ''
            ? EmploymentStatus::Regular
            : $this->match(EmploymentStatus::active(), $value('status'), fn (EmploymentStatus $status) => $status->value);

        if ($status === null) {
            $errors[] = "unknown status \"{$value('status')}\" (use trainee, probationary, regular or project based)";
            $status = EmploymentStatus::Regular;
        }

        return [[
            'name' => $value('name'),
            'email' => Str::lower($value('email')),
            'employee_number' => EmployeeNumber::normalize($value('employee_number')),
            'roles' => array_values(array_unique($roles, SORT_REGULAR)),
            'position' => $position,
            'hire_date' => $value('hire_date') !== '' && $errors === [] ? CarbonImmutable::parse($value('hire_date'))->toDateString() : null,
            'status' => $status,
        ], $errors];
    }

    /**
     * The case whose value or label matches $text, ignoring case.
     *
     * @template T of \BackedEnum
     *
     * @param  array<T>  $cases
     * @param  callable(T): string  $label
     * @return T|null
     */
    private function match(array $cases, string $text, callable $label): ?\BackedEnum
    {
        foreach ($cases as $case) {
            if (strcasecmp((string) $case->value, $text) === 0 || strcasecmp($label($case), $text) === 0 || strcasecmp(str_replace('_', ' ', (string) $case->value), $text) === 0) {
                return $case;
            }
        }

        return null;
    }
}
