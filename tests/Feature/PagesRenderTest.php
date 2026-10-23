<?php

use App\Livewire\Admin\Payroll;
use App\Livewire\Attendance\History;
use App\Livewire\Payroll\MyPayslips;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Livewire\Livewire;

/**
 * Smoke test with the full demo dataset: every page renders with real data,
 * which catches Blade mistakes that empty tables would hide.
 */
beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('renders every page for the Super Admin', function () {
    $admin = User::query()->where('email', 'superadmin@example.test')->sole();

    foreach (['dashboard', 'attendance-log', 'leave-requests', 'overtime-requests', 'employees', 'payroll', 'tax-settings',
        'holidays', 'shifts', 'roles', 'audit-log', 'announcements', 'load-requests', 'profile', 'account-settings', 'help'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertOk();
    }

    // A system account, not an employee: no attendance, leave, overtime, payslips or load of its own.
    foreach (['attendance', 'leave', 'overtime', 'payslips', 'load'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertForbidden();
    }

    $this->actingAs($admin)->get(route('employees.show', EmployeeProfile::query()->first()))->assertOk();
});

it('renders a payroll run and a payslip modal with seeded figures', function () {
    $admin = User::query()->where('email', 'accounting@example.test')->sole();
    $paidRun = PayrollRun::query()->where('status', 'paid')->sole();
    $maria = User::query()->where('email', 'employee@example.test')->sole();

    Livewire::actingAs($admin)->test(Payroll::class)
        ->call('viewRun', $paidRun->id)
        ->assertSee('Maria Santos')
        ->call('viewPayslip', $maria->id)
        ->assertSee('Net pay')
        ->assertSee('SSS salary loan');
});

it('renders an employee\'s history in every mode and their payslips', function () {
    $maria = User::query()->where('email', 'employee@example.test')->sole();

    Livewire::actingAs($maria)->test(History::class)
        ->call('setRange', 30)->assertOk()
        ->call('setMode', 'month')->call('previousMonth')->assertOk()
        ->call('setMode', 'year')->assertOk();

    Livewire::actingAs($maria)->test(MyPayslips::class)
        ->call('viewRun', PayrollRun::query()->where('status', 'paid')->value('id'))
        ->assertSee('Net pay');
});
