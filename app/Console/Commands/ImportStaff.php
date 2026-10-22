<?php

namespace App\Console\Commands;

use App\Actions\People\ImportStaff as ImportStaffAction;
use Illuminate\Console\Command;

/**
 * Creates the office ("special") accounts from a CSV kept on the server only:
 *
 *   name,email,employee_number,job,role,hire_date,status
 *   Juan Dela Cruz,owner@company.com,OFF-0001,Admin,Super Admin,2015-03-01,regular
 *   Ana Reyes,hr@company.com,OFF-0002,HR,HR,,
 *
 * job, hire_date and status are optional (status defaults to regular). Several
 * roles go in one cell separated by "|". Nothing is created if any row is
 * wrong. Each new person is emailed a "set your password" link.
 */
final class ImportStaff extends Command
{
    protected $signature = 'app:import-staff {file : Path to the CSV} {--no-email : Create the accounts without sending set-password links}';

    protected $description = 'Create office accounts (HR record, account, roles) from a private CSV and email set-password links';

    public function handle(ImportStaffAction $import): int
    {
        $path = (string) $this->argument('file');
        $handle = is_readable($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            $this->error("Can't read {$path}.");

            return self::FAILURE;
        }

        $header = fgetcsv($handle, escape: '\\');
        if ($header === false) {
            $this->error('The file is empty.');

            return self::FAILURE;
        }

        // "Employee number", "employee_number" and "EMPLOYEE NUMBER" all work; a BOM from Excel is dropped.
        $keys = array_map(fn (?string $column) => str_replace(' ', '_', strtolower(trim(ltrim((string) $column, "\u{FEFF}")))), $header);

        $missing = array_diff(['name', 'email', 'employee_number', 'role'], $keys);
        if ($missing !== []) {
            $this->error('Missing column(s): '.implode(', ', $missing).'. Expected: name, email, employee_number, job, role, hire_date, status.');

            return self::FAILURE;
        }

        $rows = [];
        while (($cells = fgetcsv($handle, escape: '\\')) !== false) {
            if ($cells === [null] || implode('', array_map(fn (?string $cell) => trim((string) $cell), $cells)) === '') {
                continue;   // blank line
            }

            $rows[] = array_combine($keys, array_map(fn (?string $cell) => (string) $cell, array_pad(array_slice($cells, 0, count($keys)), count($keys), '')));
        }
        fclose($handle);

        $result = $import->handle($rows, sendLinks: ! $this->option('no-email'));

        if ($result['errors'] !== []) {
            $this->error('Nothing was created. Fix these rows and run it again:');
            foreach ($result['errors'] as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        foreach ($result['created'] as $email) {
            $this->info("Created {$email}");
        }
        foreach ($result['skipped'] as $skip) {
            $this->warn("Skipped {$skip}");
        }

        $this->newLine();
        $this->line(count($result['created']).' created, '.count($result['skipped']).' skipped.');

        if ($result['created'] !== []) {
            $this->line($this->option('no-email')
                ? 'No emails sent: each person sets a password with "Forgot password" on the sign-in page.'
                : 'Each new person was emailed a link to set their password (valid 60 minutes; after that, "Forgot password" on the sign-in page sends a new one).');
            $this->line('Next: delete the CSV from the server, set the rates on Daily Rates, and have everyone turn on two-factor authentication.');
        }

        return self::SUCCESS;
    }
}
