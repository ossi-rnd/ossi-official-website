<?php

use App\Enums\EmploymentStatus;
use App\Enums\Position;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Support\Facades\Notification;

function staffCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'staff').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

it('creates the office accounts with their records and roles, and emails set-password links', function () {
    Notification::fake();
    $csv = staffCsv("\u{FEFF}Name,Email,Employee number,Job,Role,Hire date,Status\n"
        ."Juan Owner,Owner@Company.test,150301NCR-0001,Admin,Super Admin,2015-03-01,regular\n"
        ."Ana Reyes,hr@company.test,180115NCR-0002,HR,HR|Admin,,\n"
        ."\n"
        ."Liza Cruz,accounting@company.test,190601NCR-0003,accounting,accounting,,probationary\n");

    $this->artisan('app:import-staff', ['file' => $csv])
        ->expectsOutputToContain('3 created, 0 skipped.')
        ->assertSuccessful();

    $owner = User::query()->where('email', 'owner@company.test')->sole();
    expect($owner->roleList())->toEqualCanonicalizing([Role::Employee, Role::SuperAdmin])
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and($owner->employeeProfile)
        ->employee_number->toBe('150301NCR-0001')
        ->position->toBe(Position::AdminStaff)
        ->is_office_staff->toBeTrue()
        ->employment_status->toBe(EmploymentStatus::Regular)
        ->and($owner->employeeProfile->hire_date->toDateString())->toBe('2015-03-01');

    expect(User::query()->where('email', 'hr@company.test')->sole()->roleList())->toEqualCanonicalizing([Role::Employee, Role::Hr, Role::Admin])
        ->and(EmployeeProfile::query()->where('employee_number', '190601NCR-0003')->sole()->employment_status)->toBe(EmploymentStatus::Probationary);

    Notification::assertSentTo($owner, QueuedResetPassword::class);
    Notification::assertCount(3);
    expect(ActivityLog::query()->where('event', 'staff.imported')->count())->toBe(3);

    // Running it again changes nothing.
    $this->artisan('app:import-staff', ['file' => $csv, '--no-email' => true])
        ->expectsOutputToContain('0 created, 3 skipped.')
        ->assertSuccessful();
    expect(User::query()->count())->toBe(3);
});

it('creates nothing when any row is wrong, and says which', function () {
    Notification::fake();
    $csv = staffCsv("name,email,employee_number,job,role\n"
        ."Good Person,good@company.test,150301NCR-0001,IT,Developer\n"
        ."Bad Role,bad@company.test,180115NCR-0002,IT,Boss\n"
        ."No Email,,190601NCR-0003,HR,HR\n"
        ."Twice,good@company.test,200210NCR-0004,HR,HR\n"
        ."Bad Number,number@company.test,EMP-1,HR,HR\n");

    $this->artisan('app:import-staff', ['file' => $csv])
        ->expectsOutputToContain('Nothing was created')
        ->expectsOutputToContain('Line 3 (bad@company.test): unknown role "Boss"')
        ->expectsOutputToContain('Line 5 (good@company.test): appears twice in the file')
        ->expectsOutputToContain('Line 6 (number@company.test): employee number "EMP-1": use the number on the OSSI ID card')
        ->assertFailed();

    expect(User::query()->count())->toBe(0)->and(EmployeeProfile::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('refuses a file without the required columns', function () {
    $this->artisan('app:import-staff', ['file' => staffCsv("name,email\nA,a@company.test\n")])
        ->expectsOutputToContain('Missing column(s): employee_number, role')
        ->assertFailed();
});
