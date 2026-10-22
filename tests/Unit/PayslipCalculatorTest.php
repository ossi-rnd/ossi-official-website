<?php

use App\Data\CarryoverLine;
use App\Data\LateBracket;
use App\Data\LoanLine;
use App\Data\PayslipInput;
use App\Data\RateSchedule;
use App\Services\Payroll\PayslipCalculator;

/**
 * Pure unit tests: no Laravel, no database. The rates are built by hand, so
 * each test states exactly what it depends on.
 */
function rates(): RateSchedule
{
    return new RateSchedule(
        effectiveFrom: '1970-01-01',
        dailyRate: ['field' => 610, 'office' => 900],
        sss: ['field' => 581, 'office' => 900],
        philhealth: ['field' => 225, 'office' => 400],
        pagibig: ['field' => 100, 'office' => 100],
        withholdingRate: ['field' => 0.0, 'office' => 0.02],
        holidayPremiums: ['regular' => 1.00, 'special_non_working' => 0.30],
        nightDifferentialRate: 0.10,
        overtimeMultiplier: 1.25,
        standardHoursPerDay: 8,
    );
}

it('computes the worked example from the spec to net 7,787', function () {
    // Field guard, 13 days, 120 approved OT minutes, 30 minutes late, no brackets, 1 regular holiday worked.
    $payslip = (new PayslipCalculator)->calculate(new PayslipInput(
        isOfficeStaff: false,
        nightMinutes: 0,
        daysWorked: 13,
        lateMinutes: 30,
        overtimeMinutes: 120,
        holidayDaysWorked: ['regular' => 1],
    ), rates());

    expect($payslip->basicPay)->toBe(7930.0)
        ->and($payslip->overtimePay)->toBe(191.0)
        ->and($payslip->holidayPay)->toBe(610.0)
        ->and($payslip->grossPay())->toBe(8731.0)
        ->and($payslip->governmentDeductions())->toBe(906.0)
        ->and($payslip->lateDeduction)->toBe(38.0)
        ->and($payslip->netPay())->toBe(7787.0);
});

it('pays a worked special non-working day 30% on top, and an unworked holiday nothing', function (array $holidays, float $premium) {
    $payslip = (new PayslipCalculator)->calculate(new PayslipInput(false, 0, 1, 0, 0, $holidays), rates());

    expect($payslip->holidayPay)->toBe($premium);
})->with([
    'worked regular holiday' => [['regular' => 1], 610.0],
    'worked special day' => [['special_non_working' => 1], 183.0],
    'no holiday worked' => [[], 0.0],
]);

it('exempts field staff from withholding tax and charges office staff 2% of taxable gross', function () {
    $calculator = new PayslipCalculator;

    $field = $calculator->calculate(new PayslipInput(false, 0, 10, 0, 0), rates());
    $office = $calculator->calculate(new PayslipInput(true, 0, 10, 0, 0), rates());

    expect($field->withholdingTax)->toBe(0.0)
        ->and($field->isMinimumWageEarner())->toBeTrue()
        ->and($office->withholdingTax)->toBe(180.0) // 2% of 900 × 10
        ->and($office->sss)->toBe(900.0);
});

it('pays 10% night differential only for the minutes actually worked 10 PM–6 AM', function () {
    $calculator = new PayslipCalculator;

    // 10 nights × 8 h = 4,800 min × (₱610 ÷ 480) × 10% = ₱610.
    $nights = $calculator->calculate(new PayslipInput(false, 4800, 10, 0, 0), rates());
    expect($nights->nightDifferential)->toBe(610.0)->and($nights->nightMinutes)->toBe(4800)
        // 2 night hours on one day: 120 × 1.2708 × 10% = ₱15.25 → ₱15.
        ->and($calculator->calculate(new PayslipInput(false, 120, 1, 0, 0), rates())->nightDifferential)->toBe(15.0)
        ->and($calculator->calculate(new PayslipInput(false, 0, 10, 0, 0), rates())->nightDifferential)->toBe(0.0);
});

it('uses the matching late bracket, or nothing when brackets exist but none match', function (int $lateMinutes, float $expected) {
    $brackets = [new LateBracket(1, 15, 50.0), new LateBracket(16, 60, 150.0), new LateBracket(120, null, 500.0)];

    $payslip = (new PayslipCalculator)->calculate(new PayslipInput(false, 0, 10, $lateMinutes, 0, [], [], $brackets), rates());

    expect($payslip->lateDeduction)->toBe($expected);
})->with([
    'bottom of first bracket' => [1, 50.0],
    'top of second bracket' => [60, 150.0],
    'gap between brackets' => [90, 0.0],
    'open-ended bracket' => [600, 500.0],
    'not late' => [0, 0.0],
]);

it('subtracts every loan installment from net pay', function () {
    $loans = [new LoanLine(1, 'SSS loan', 'loan', 500.0, 6000.0), new LoanLine(2, 'Uniform', 'uniform', 150.0, null)];

    $payslip = (new PayslipCalculator)->calculate(new PayslipInput(false, 0, 13, 0, 0, [], $loans), rates());

    expect($payslip->loanDeductions())->toBe(650.0)
        ->and($payslip->netPay())->toBe(7930.0 - 906.0 - 650.0);
});

it('never lets net pay go below zero: deductions stop and the rest is a shortfall', function () {
    // One day: ₱610 gross, but ₱906 of contributions are due.
    $payslip = (new PayslipCalculator)->calculate(new PayslipInput(false, 0, 1, 0, 0), rates());

    expect($payslip->grossPay())->toBe(610.0)
        ->and($payslip->sss)->toBe(581.0)              // taken in full: first in line
        ->and($payslip->philhealth)->toBe(29.0)        // what was left
        ->and($payslip->pagibig)->toBe(0.0)
        ->and($payslip->netPay())->toBe(0.0)
        ->and($payslip->shortfallLines)->toBe([
            ['label' => 'PhilHealth', 'amount' => 196.0, 'carries' => true],
            ['label' => 'Pag-IBIG', 'amount' => 100.0, 'carries' => true],
        ]);
});

it('takes carried-over amounts after contributions and before late and loans; unpaid loans are not carried', function () {
    $payslip = (new PayslipCalculator)->calculate(new PayslipInput(
        isOfficeStaff: false,
        nightMinutes: 0,
        daysWorked: 2,                                 // ₱1,220 gross − ₱906 contributions = ₱314 left
        lateMinutes: 0,
        overtimeMinutes: 0,
        loans: [new LoanLine(7, 'Cash advance', 'cash_advance', 500.0, 1000.0)],
        carryovers: [new CarryoverLine(3, 'PhilHealth (from Oct 1–15, 2026)', 196.0)],
    ), rates());

    expect($payslip->carryoverLines)->toBe([['carryover_id' => 3, 'label' => 'PhilHealth (from Oct 1–15, 2026)', 'amount' => 196.0]])
        ->and($payslip->loanLines[0]['amount'])->toBe(118.0)  // 314 − 196
        ->and($payslip->netPay())->toBe(0.0)
        ->and($payslip->shortfallLines)->toBe([['label' => 'Cash advance', 'amount' => 382.0, 'carries' => false]]);
});

it('leaves a normal payslip untouched: no shortfall when the pay covers everything', function () {
    $payslip = (new PayslipCalculator)->calculate(new PayslipInput(false, 0, 13, 0, 0), rates());

    expect($payslip->shortfallLines)->toBe([])
        ->and($payslip->netPay())->toBe(7024.0);
});
