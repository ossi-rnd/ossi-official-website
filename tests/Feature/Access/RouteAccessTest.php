<?php

use App\Enums\Ability;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Livewire\Admin\LeaveRequests;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use Livewire\Livewire;

/**
 * Spec acceptance: "Each role can open exactly the screens its
 * gates allow, and gets a 403 everywhere else." Built from the Ability enum,
 * so a change to the access rules changes this test automatically.
 */
dataset('staff pages', function () {
    $pages = [
        'dashboard' => Ability::AccessEmployerArea,
        'attendance-log' => Ability::SuperviseAttendance,
        'leave-requests' => Ability::MonitorRequests,
        'overtime-requests' => Ability::MonitorRequests,
        'employees' => Ability::ViewPeople,
        'payroll' => Ability::ManagePayroll,
        'late-deductions' => Ability::ManagePayroll,
        'tax-settings' => Ability::ManagePayroll,
        'holidays' => Ability::ConfigureHolidays,
        'shifts' => Ability::ConfigureSystem,
        'clients' => Ability::ManageClients,
        'offices' => Ability::ManageOffices,
        'roles' => Ability::ConfigureSystem,
        'audit-log' => Ability::ViewSystemLogs,
        'announcements' => Ability::PublishContent,
        'load-requests' => Ability::ManageLoadRequests,
        'relievers' => Ability::ManageRelievers,
        'roster' => Ability::ManageRoster,
        'patrols' => Ability::ManagePatrols,
        'office-away' => Ability::MonitorOfficeAway,
        'documents' => Ability::ManageDocuments,
        'trainings' => Ability::ManageTraining,
        'radios' => Ability::ManageRadios,
        'referral-cases' => Ability::ManageReferrals,
        // HR, or the Executive for HR staff's own complaints.
        'complaint-cases' => [Ability::HandleComplaints, Ability::HandleHrComplaints],
    ];

    foreach (Role::cases() as $role) {
        foreach ($pages as $route => $abilities) {
            $allowed = collect(is_array($abilities) ? $abilities : [$abilities])->contains(fn (Ability $ability) => in_array($role, $ability->roles(), true));
            yield "{$role->value} → {$route} (".($allowed ? '200' : '403').')' => [$role, $route, $allowed];
        }
    }
});

it('opens exactly the staff pages a role\'s gates allow', function (Role $role, string $route, bool $allowed) {
    $this->actingAs(staff($role))->get(route($route))->assertStatus($allowed ? 200 : 403);
})->with('staff pages');

it('gives every signed-in user the self-service pages, and attendance and pay to those paid through payroll', function (Role $role) {
    $user = staff($role);
    $paidHere = ! in_array($role, Role::paidOutsidePayroll(), true);

    foreach (['profile', 'account-settings', 'help', 'company-policy'] as $route) {
        $this->actingAs($user)->get(route($route))->assertOk();
    }

    // Resignation too: every role but the Executive (paid outside payroll) may file one.
    foreach (['attendance', 'leave', 'overtime', 'payslips', 'load', 'absence-notice', 'complaints', 'resignation'] as $route) {
        $this->actingAs($user)->get(route($route))->assertStatus($paidHere ? 200 : 403);
    }
})->with(Role::cases());

it('sends guests to the login page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['attendance', 'dashboard', 'payroll', 'employees', 'audit-log']);

it('opens an employee record for viewers, with editing only for people managers', function () {
    $profile = EmployeeProfile::factory()->create();

    $this->actingAs(staff(Role::Hr))->get(route('employees.show', $profile))->assertOk()->assertSee('Edit record');
    $this->actingAs(staff(Role::Accounting))->get(route('employees.show', $profile))->assertOk()->assertDontSee('Edit record');
    $this->actingAs(staff(Role::Developer))->get(route('employees.show', $profile))->assertForbidden();
});

it('blocks the next write once a role is removed, even from a page already open', function () {
    $hr = staff(Role::Hr);
    $leave = new LeaveRequest(['type' => 'vacation', 'start_date' => today()->addDay(), 'end_date' => today()->addDay(), 'reason' => 'Rest']);
    $leave->user()->associate(employee());
    $leave->save();

    $page = Livewire::actingAs($hr)->test(LeaveRequests::class)->call('startReview', $leave->id);

    $hr->roles()->delete();
    // Every real request loads the user fresh from the session, so do the same here.
    $this->actingAs($hr->fresh());

    $page->call('approve')->assertForbidden();

    expect($leave->fresh()?->status)->toBe(RequestStatus::Pending);
});
