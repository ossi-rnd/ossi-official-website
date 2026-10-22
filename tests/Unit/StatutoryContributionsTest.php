<?php

use App\Data\StatutoryRules;
use App\Services\Payroll\StatutoryContributions;

/**
 * The 2026 government rules, with plain numbers. If a circular changes, the
 * values move in config/payroll.php; these tests pin the FORMULAS.
 */
function rules2026(): StatutoryRules
{
    return new StatutoryRules(
        sssEmployeeRate: 0.05, sssEmployerRate: 0.10, sssMscMin: 5000, sssMscMax: 35000, sssMscStep: 500,
        sssEcLow: 10, sssEcHigh: 30, sssEcHighFromMsc: 15000,
        philhealthRate: 0.05, philhealthFloor: 10000, philhealthCeiling: 100000,
        pagibigEmployeeRate: 0.02, pagibigEmployeeRateLow: 0.01, pagibigLowThreshold: 1500, pagibigEmployerRate: 0.02, pagibigMaxFundSalary: 10000,
        withholdingSemiMonthly: [
            ['over' => 0.0, 'base' => 0.0, 'rate' => 0.0],
            ['over' => 10417.0, 'base' => 0.0, 'rate' => 0.15],
            ['over' => 16667.0, 'base' => 937.50, 'rate' => 0.20],
            ['over' => 33333.0, 'base' => 4270.70, 'rate' => 0.25],
            ['over' => 83333.0, 'base' => 16770.70, 'rate' => 0.30],
            ['over' => 333333.0, 'base' => 91770.70, 'rate' => 0.35],
        ],
    );
}

it('puts compensation in the right SSS salary credit bracket', function (float $compensation, float $msc, float $employee, float $employer, float $ec) {
    expect((new StatutoryContributions)->sss(rules2026(), $compensation))->toBe(['msc' => $msc, 'employee' => $employee, 'employer' => $employer, 'ec' => $ec]);
})->with([
    'below the floor' => [3000, 5000, 250, 500, 10],
    'top of the ₱5,000 bracket' => [5249.99, 5000, 250, 500, 10],
    'start of the ₱5,500 bracket' => [5250, 5500, 275, 550, 10],
    'a guard month (₱15,860)' => [15860, 16000, 800, 1600, 30],
    'EC switches at MSC ₱15,000' => [14750, 15000, 750, 1500, 30],
    'just below that' => [14749.99, 14500, 725, 1450, 10],
    'at the ceiling' => [34750, 35000, 1750, 3500, 30],
    'far above the ceiling' => [120000, 35000, 1750, 3500, 30],
]);

it('applies the PhilHealth floor and ceiling and splits the premium equally', function (float $basic, float $base, float $share) {
    expect((new StatutoryContributions)->philhealth(rules2026(), $basic))->toBe(['base' => $base, 'employee' => $share, 'employer' => $share]);
})->with([
    'below the ₱10,000 floor' => [7930, 10000, 250],
    'a guard month' => [15860, 15860, 396.5],
    'above the ₱100,000 ceiling' => [150000, 100000, 2500],
]);

it('caps Pag-IBIG at the maximum fund salary and uses 1% for very low pay', function (float $compensation, float $employee, float $employer) {
    $result = (new StatutoryContributions)->pagibig(rules2026(), $compensation);

    expect($result['employee'])->toBe($employee)->and($result['employer'])->toBe($employer);
})->with([
    '₱1,500 or less: 1% employee' => [1500, 15, 30],
    'just above: 2%' => [1600, 32, 32],
    'capped at ₱10,000' => [25000, 200, 200],
]);

it('withholds by the BIR semi-monthly table', function (float $taxable, float $tax) {
    expect((new StatutoryContributions)->withholdingSemiMonthly(rules2026(), $taxable))->toBe($tax);
})->with([
    'under ₱10,417: nothing' => [10000, 0.0],
    '15% over ₱10,417' => [10720, 45.45],
    'top of the 15% bracket' => [16667, 937.5],
    '20% bracket' => [20000, 1604.1],
    '25% bracket' => [50000, 8437.45],
]);
