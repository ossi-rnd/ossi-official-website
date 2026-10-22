<?php

use App\Models\MinimumWage;
use App\Models\StatutoryRuleSet;
use App\Services\Payroll\RateScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The resolver reads Accounting's saved rule versions and minimum wages from the database.
uses(TestCase::class, RefreshDatabase::class);

it('lets a saved rule version override the built-in rules from its date on', function () {
    $rules = config('payroll.schedules.2026-01-01.statutory');
    $rules['sss']['employee_rate'] = 0.06;
    $version = new StatutoryRuleSet;
    $version->forceFill(['effective_from' => '2026-11-01', 'rules' => $rules])->save();

    $resolver = new RateScheduleResolver;

    expect($resolver->forDate(CarbonImmutable::parse('2026-10-31'))->statutory?->sssEmployeeRate)->toBe(0.05)
        ->and($resolver->forDate(CarbonImmutable::parse('2026-11-01'))->statutory?->sssEmployeeRate)->toBe(0.06);
});

it('merges saved minimum wages with the built-in list', function () {
    $wage = new MinimumWage;
    $wage->forceFill(['effective_from' => '2027-01-01', 'daily_amount' => 780, 'wage_order' => 'NCR-29'])->save();

    $resolver = new RateScheduleResolver;

    expect($resolver->forDate(CarbonImmutable::parse('2026-10-01'))->minimumWage)->toBe(755.0)
        ->and($resolver->forDate(CarbonImmutable::parse('2027-01-02'))->minimumWage)->toBe(780.0)
        ->and(array_keys($resolver->minimumWages()))->toBe(['2025-07-17', '2026-09-26', '2027-01-01']);
});

it('uses the schedule in force on the given date, not the newest one', function () {
    config()->set('payroll.schedules.2027-01-01', [
        'daily_rate' => ['field' => 700, 'office' => 1000],
        'sss' => ['field' => 600, 'office' => 950],
        'philhealth' => ['field' => 250, 'office' => 450],
        'pagibig' => ['field' => 200, 'office' => 200],
        'withholding_rate' => ['field' => 0, 'office' => 0.03],
    ]);

    $resolver = new RateScheduleResolver;

    expect($resolver->forDate(CarbonImmutable::parse('2026-12-31'))->dailyRate(false))->toBe(610.0)
        ->and($resolver->forDate(CarbonImmutable::parse('2027-01-01'))->dailyRate(false))->toBe(700.0)
        ->and($resolver->forDate(CarbonImmutable::parse('2027-06-15'))->effectiveFrom)->toBe('2027-01-01');
});

it('fails loudly when no schedule covers the date', function () {
    config()->set('payroll.schedules', ['2030-01-01' => config('payroll.schedules.1970-01-01')]);

    (new RateScheduleResolver)->forDate(CarbonImmutable::parse('2026-01-01'));
})->throws(RuntimeException::class);
