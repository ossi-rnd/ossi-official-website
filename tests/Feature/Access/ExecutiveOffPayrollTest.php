<?php

use App\Enums\Role;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Queries\Attendance\TodayRosterQuery;
use App\Support\Navigation;

// The Executive is paid outside this payroll (owner/director level), even with an HR record.

beforeEach(function () {
    $this->executive = staff(Role::President);
    $this->accountant = staff(Role::Accounting);
});

it('leaves the Executive out of payroll, the DTR and the daily-rate list', function () {
    expect(User::query()->onPayroll()->pluck('id')->all())->toContain($this->accountant->id)->not->toContain($this->executive->id)
        ->and(EmployeeProfile::query()->onPayroll()->pluck('user_id')->all())->toContain($this->accountant->id)->not->toContain($this->executive->id)
        ->and(collect(app(TodayRosterQuery::class)->handle())->pluck('id')->all())->not->toContain($this->executive->id);
});

it('shows the Executive no personal attendance or pay pages, and refuses a typed URL', function () {
    $personal = collect(Navigation::for($this->executive))->firstWhere('title', 'Personal');

    expect(collect($personal['items'] ?? [])->pluck('route')->all())->toBe(['company-policy', 'help']);

    foreach (['attendance', 'leave', 'overtime', 'payslips', 'load', 'complaints', 'absence-notice'] as $route) {
        $this->actingAs($this->executive)->get(route($route))->assertForbidden();
    }

    $this->actingAs($this->executive)->get(route('help'))->assertOk();
    $this->actingAs($this->executive)->get(route('company-policy'))->assertOk();
    $this->actingAs($this->accountant)->get(route('attendance'))->assertOk();
});
