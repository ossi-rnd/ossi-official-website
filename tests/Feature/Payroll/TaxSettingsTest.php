<?php

use App\Actions\Payroll\GeneratePayslip;
use App\Enums\Role;
use App\Livewire\Admin\TaxSettings;
use App\Models\ActivityLog;
use App\Models\MinimumWage;
use App\Models\StatutoryRuleSet;
use App\Models\TimeLog;
use App\Queries\Payroll\ResolvePayslips;
use App\Services\Payroll\RateScheduleResolver;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00'));
    $this->accounting = staff(Role::Accounting);
});

it('shows the rules in force, from the built-in defaults until a version is saved', function () {
    Livewire::actingAs($this->accounting)->test(TaxSettings::class)
        ->assertSee('Built-in rules')
        ->assertSee('5% / 10%')        // SSS employee / employer
        ->assertSee('₱10,417')         // first taxed bracket
        ->assertSee('₱755.00');        // NCR-28 minimum wage
});

it('saves a new version as percentages, and payroll from that date uses it', function () {
    $run = payrollRun('2026-11-01', '2026-11-15');
    $guard = employee();
    foreach (range(2, 6) as $day) {
        TimeLog::factory()->on(CarbonImmutable::parse("2026-11-0{$day}"))->create(['user_id' => $guard->id]);
    }
    $before = app(ResolvePayslips::class)->forUser($run, $guard)->sss;

    Livewire::actingAs($this->accounting)->test(TaxSettings::class)
        ->call('startVersion')
        ->assertSet('rules.sss.employee_rate', '5')
        ->set('effectiveFrom', '2026-11-01')
        ->set('note', 'Test circular')
        ->set('rules.sss.employee_rate', '6')
        ->call('saveVersion')
        ->assertHasNoErrors();

    $version = StatutoryRuleSet::query()->sole();
    expect($version->rules['sss']['employee_rate'])->toBe(0.06)
        ->and($version->rules['withholding_semi_monthly'])->toHaveCount(6);

    app()->forgetInstance(RateScheduleResolver::class);
    expect(app(ResolvePayslips::class)->forUser($run, $guard)->sss)->toBe(round($before * 6 / 5, 2));
    expect(ActivityLog::query()->where('event', 'statutory.saved')->count())->toBe(1);
});

it('refuses a version or minimum wage that would rewrite generated payslips', function () {
    $run = payrollRun('2026-10-01', '2026-10-15');
    $guard = employee();
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-02'))->create(['user_id' => $guard->id]);
    app(GeneratePayslip::class)->handle($run, $guard, $this->accounting);

    Livewire::actingAs($this->accounting)->test(TaxSettings::class)
        ->call('startVersion')
        ->assertSet('effectiveFrom', '2026-10-16')   // suggested: the day after the frozen period
        ->set('effectiveFrom', '2026-10-10')
        ->call('saveVersion')
        ->assertHasErrors(['effectiveFrom'])
        ->set('wageFrom', '2026-10-10')->set('wageAmount', '800')
        ->call('saveWage')
        ->assertHasErrors(['wageFrom']);

    expect(StatutoryRuleSet::query()->count())->toBe(0)->and(MinimumWage::query()->count())->toBe(0);
});

it('adds a wage order, which changes who is a minimum wage earner', function () {
    $run = payrollRun('2027-01-01', '2027-01-15');
    $guard = employee();
    TimeLog::factory()->on(CarbonImmutable::parse('2027-01-04'))->create(['user_id' => $guard->id]);

    // ₱610 default: at or below ₱755 → exempt. A (hypothetical) ₱500 minimum makes them taxable.
    expect(app(ResolvePayslips::class)->forUser($run, $guard)->isMinimumWageEarner())->toBeTrue();

    Livewire::actingAs($this->accounting)->test(TaxSettings::class)
        ->set('wageFrom', '2027-01-01')->set('wageAmount', '500')->set('wageOrder', 'TEST-1')
        ->call('saveWage')->assertHasNoErrors()
        ->assertSee('TEST-1');

    app()->forgetInstance(RateScheduleResolver::class);
    expect(app(ResolvePayslips::class)->forUser($run, $guard)->isMinimumWageEarner())->toBeFalse();
});

it('deletes an unused version and keeps the page to payroll managers', function () {
    $version = new StatutoryRuleSet;
    $version->forceFill(['effective_from' => '2026-12-01', 'rules' => config('payroll.schedules.2026-01-01.statutory')])->save();

    Livewire::actingAs($this->accounting)->test(TaxSettings::class)->call('deleteVersion', $version->id)->assertHasNoErrors();
    expect(StatutoryRuleSet::query()->count())->toBe(0);

    $this->actingAs(staff(Role::Hr))->get(route('tax-settings'))->assertForbidden();
});

it('explains the PH rules and computes a month in the calculator with the real payroll formulas', function () {
    // 26 days at ₱755 (the NCR minimum): ₱19,630 a month → MSC ₱19,500 → SSS ₱975;
    // PhilHealth 2.5% of ₱19,630 = ₱490.75; Pag-IBIG ₱200; minimum wage earner → no tax.
    $page = Livewire::actingAs($this->accounting)->test(TaxSettings::class)
        ->set('tab', 'law')
        ->assertSee('Republic Act 11199')
        ->assertSee('Universal Health Care Act')
        ->assertSee('RA 10963')
        ->assertSee('13th month pay')
        ->set('calcDailyRate', '755')->set('calcDays', '26');

    $calc = $page->instance()->calculation;
    expect($calc['first']->sss + $calc['second']->sss)->toBe(975.0)
        ->and($calc['first']->philhealth + $calc['second']->philhealth)->toBe(490.75)
        ->and($calc['first']->pagibig + $calc['second']->pagibig)->toBe(200.0)
        ->and($calc['first']->withholdingTax + $calc['second']->withholdingTax)->toBe(0.0)
        ->and($calc['first']->isMinimumWageEarner())->toBeTrue();

    // ₱1,200 a day for 26 days: above the minimum, so the BIR table applies on each cutoff.
    $page->set('calcDailyRate', '1200');
    $calc = $page->instance()->calculation;
    expect($calc['first']->isMinimumWageEarner())->toBeFalse()
        ->and($calc['first']->withholdingTax)->toBeGreaterThan(0.0);
    $page->assertSee('Above the minimum wage');
});
