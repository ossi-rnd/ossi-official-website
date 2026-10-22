<?php

use App\Actions\Payroll\GeneratePayslip;
use App\Actions\Payroll\SetPositionRate;
use App\Actions\People\SetEmployeeRate;
use App\Enums\Position;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Livewire\Admin\DailyRates;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Employees;
use App\Livewire\Admin\Payroll;
use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use App\Models\TimeLog;
use App\Notifications\DailyRateNeeded;
use App\Queries\Payroll\ResolvePayslips;
use App\Queries\People\EffectiveRatesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00'));
    $this->accounting = staff(Role::Accounting);
});

it('pays everyone in a position its company rate, unless they have a personal rate', function () {
    $guard = employee();                                                   // Security Guard
    $senior = employee(['position' => Position::ShiftInCharge]);
    $special = employee();

    Livewire::actingAs($this->accounting)->test(DailyRates::class)
        ->set('tab', 'positions')
        ->assertSee('Not set · default')
        ->call('startPositionChange', Position::SecurityGuard->value)
        ->set('rateAmount', '780')->set('rateEffectiveFrom', '2026-10-01')->set('rateNote', '2026 rate card')
        ->call('savePositionRate')->assertHasNoErrors()
        ->assertSee('₱780.00');

    app(SetEmployeeRate::class)->handle($special->employeeProfile, 820, CarbonImmutable::parse('2026-10-01'), null, $this->accounting);

    $rates = app(EffectiveRatesQuery::class)->handle(EmployeeProfile::query()->get(), CarbonImmutable::parse('2026-10-01'));
    expect($rates[$guard->employeeProfile->id]->rate)->toBe(780.0)
        ->and($rates[$guard->employeeProfile->id]->source)->toBe(RateSource::Position)
        ->and($rates[$special->employeeProfile->id]->rate)->toBe(820.0)          // personal wins
        ->and($rates[$special->employeeProfile->id]->source)->toBe(RateSource::Personal)
        ->and($rates[$senior->employeeProfile->id]->source)->toBe(RateSource::Default);   // no Shift-in-Charge rate yet

    // The payslip follows the position rate.
    $run = payrollRun('2026-10-01', '2026-10-15');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-02'))->create(['user_id' => $guard->id]);
    expect(app(ResolvePayslips::class)->forUser($run, $guard)->dailyRate)->toBe(780.0);

    expect(ActivityLog::query()->where('event', 'position_rate.set')->count())->toBe(1);
});

it('counts only employees with neither a personal nor a position rate as missing, and only asks for those', function () {
    Notification::fake();
    employee();
    employee(['position' => Position::ShiftInCharge]);

    expect(EmployeeProfile::query()->active()->withoutRate()->count())->toBe(3);   // + the Accounting user (office staff)

    app(SetPositionRate::class)->handle(Position::SecurityGuard, 780, CarbonImmutable::parse('2026-10-01'), null, $this->accounting);
    expect(EmployeeProfile::query()->active()->withoutRate()->count())->toBe(2);

    // A new Security Guard is covered by the position rate: no "rate needed" notice.
    $hr = staff(Role::Hr);
    $add = fn (string $number, string $position) => Livewire::actingAs($hr)->test(Employees::class)->call('create')
        ->set('form.hire_date', '2024-06-01')->set('form.birth_province', 'PAM')->set('form.name', 'New Hire')->set('form.email', strtolower($number).'@example.test')
        ->set('form.position', $position)->call('save')->assertHasNoErrors();

    $add('240601-PAM-7001', Position::SecurityGuard->value);
    Notification::assertNotSentTo($this->accounting, DailyRateNeeded::class);

    $add('240601-PAM-7002', Position::DetachmentCommander->value);
    Notification::assertSentTo($this->accounting, DailyRateNeeded::class);
});

it('shows HR where the rate comes from, read-only', function () {
    $guard = employee();
    app(SetPositionRate::class)->handle(Position::SecurityGuard, 780, CarbonImmutable::parse('2026-10-01'), null, $this->accounting);

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $guard->employeeProfile])
        ->assertSee('₱780.00')
        ->assertSee('Position rate')
        ->assertSee('Set by Accounting');
});

it('refuses a position rate that would rewrite generated payslips, and keeps it to payroll managers', function () {
    $guard = employee();
    $run = payrollRun('2026-10-01', '2026-10-15');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-02'))->create(['user_id' => $guard->id]);
    app(GeneratePayslip::class)->handle($run, $guard, $this->accounting);

    expect(fn () => app(SetPositionRate::class)->handle(Position::SecurityGuard, 780, CarbonImmutable::parse('2026-10-10'), null, $this->accounting))
        ->toThrow(ValidationException::class, 'Choose Oct 16, 2026 or later');
    expect(fn () => app(SetPositionRate::class)->handle(Position::SecurityGuard, 780, CarbonImmutable::parse('2026-10-16'), null, staff(Role::Hr)))
        ->toThrow(AuthorizationException::class);
});

it('leaves the rate to the employer: no minimum-wage warnings on Daily Rates', function () {
    employee();                                                       // ₱610 company default in tests
    Livewire::actingAs($this->accounting)->test(DailyRates::class)
        ->assertSee('₱610.00')
        ->assertDontSee('minimum wage')
        ->assertDontSee('Below minimum');
});

it('onboards a new employee at ₱0 ("Not set") and refuses their payslip until Accounting sets a rate', function () {
    zeroCompanyDefault();
    $newHire = employee(['position' => Position::DetachmentCommander]);
    $run = payrollRun('2026-10-01', '2026-10-15');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-02'))->create(['user_id' => $newHire->id]);

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $newHire->employeeProfile])
        ->assertSee('Not set')
        ->assertDontSee('₱0.00');

    Livewire::actingAs($this->accounting)->test(DailyRates::class)
        ->assertSee('Not set')
        ->assertDontSee('paid below today');     // ₱0 is "no rate", not underpayment

    expect(fn () => app(GeneratePayslip::class)->handle($run, $newHire, $this->accounting))
        ->toThrow(ValidationException::class, 'has no daily rate yet');

    // "Generate all" skips them; the run names them.
    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $run->id)
        ->assertSee('no daily rate yet')
        ->assertSee($newHire->name)
        ->call('generateAll')
        ->assertDispatched('notify', message: 'No payslips generated. 1 without a daily rate skipped: set it on Daily Rates.');

    // Once their position has a rate, they're paid it.
    app(SetPositionRate::class)->handle(Position::DetachmentCommander, 950, CarbonImmutable::parse('2026-10-01'), null, $this->accounting);
    expect(app(GeneratePayslip::class)->handle($run, $newHire, $this->accounting)->daily_rate)->toBe('950.00');
});

it('offers office jobs (Admin, HR, Accounting, IT) with their own company rate', function () {
    expect(array_map(fn (Position $position) => $position->label(), Position::for(true)))
        ->toBe(['Admin', 'HR', 'Accounting', 'IT', 'Operations Head', 'Satellite Operations Officer'])
        ->and(Position::offered())->not->toContain(Position::SecurityManager);

    app(SetPositionRate::class)->handle(Position::ItStaff, 1100, CarbonImmutable::parse('2026-10-01'), null, $this->accounting);

    Livewire::actingAs(staff(Role::Hr))->test(Employees::class)->call('create')
        ->set('form.hire_date', '2024-06-01')->set('form.birth_province', 'PAM')->set('form.name', 'Ian Tech')->set('form.email', 'ian@example.test')
        ->set('form.is_office_staff', true)
        ->set('form.position', Position::ItStaff->value)
        ->call('save')->assertHasNoErrors();

    $profile = EmployeeProfile::query()->where('email', 'ian@example.test')->sole();
    expect($profile->position)->toBe(Position::ItStaff)
        ->and(app(EffectiveRatesQuery::class)->forProfile($profile, CarbonImmutable::today())->rate)->toBe(1100.0);

    Livewire::actingAs($this->accounting)->test(DailyRates::class)->set('tab', 'positions')
        ->assertSee('Operations Head')->assertDontSee('Security Manager')->assertSee('₱1,100.00');
});
