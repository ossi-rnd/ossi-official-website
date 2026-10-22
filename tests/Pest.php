<?php

use App\Enums\Position;
use App\Enums\Role;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\RateScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A signed-in-able person with an HR profile (on payroll) and the given roles.
 *
 * @param  array<string, mixed>  $profile
 */
function employee(array $profile = [], Role ...$roles): User
{
    return User::factory()
        ->withRoles(...($roles === [] ? [Role::Employee] : $roles))
        ->onPayroll($profile)
        ->create();
}

/**
 * Office staff holding the given role(s).
 */
function staff(Role ...$roles): User
{
    // The Super Admin, President and Vice President have no HR record (Role::withoutEmployeeRecord).
    if (array_filter($roles, fn (Role $role) => in_array($role, Role::withoutEmployeeRecord(), true)) !== []) {
        return User::factory()->withRoles(...$roles)->create();
    }

    return employee(['is_office_staff' => true, 'position' => Position::AdminStaff], ...$roles);
}

/**
 * A real JPEG as the browser's canvas.toDataURL() would send it.
 */
function cameraPhoto(): string
{
    $image = imagecreatetruecolor(32, 32);
    ob_start();
    imagejpeg($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return 'data:image/jpeg;base64,'.base64_encode($bytes);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function payrollRun(string $start, string $end, string $status = 'processing', array $attributes = []): PayrollRun
{
    $run = PayrollRun::create([
        'start_date' => $start,
        'end_date' => $end,
        'pay_date' => CarbonImmutable::parse($end)->addDays(5)->toDateString(),
        ...$attributes,
    ]);
    $run->forceFill(['status' => $status])->save();

    return $run;
}

/**
 * The real company default from 2026: ₱0, so an onboarded employee has no
 * rate until Accounting sets one (tests/TestCase.php pins ₱610/₱900 for the
 * worked examples).
 */
function zeroCompanyDefault(): void
{
    config(['payroll.schedules.2026-01-01.daily_rate' => ['field' => 0, 'office' => 0]]);
    app()->forgetInstance(RateScheduleResolver::class);
}
