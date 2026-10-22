<?php

use App\Actions\Payroll\AdvancePayrollRun;
use App\Actions\Payroll\GeneratePayslip;
use App\Actions\Payroll\GenerateRunPayslips;
use App\Actions\Payroll\SaveEmployeeDeduction;
use App\Actions\People\SetEmployeeRate;
use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Enums\PayrollRunStatus;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Livewire\Admin\Payroll;
use App\Livewire\Payroll\MyLoans;
use App\Livewire\Payroll\MyPayslips;
use App\Models\ActivityLog;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Models\PayrollCarryover;
use App\Models\PayrollRun;
use App\Models\PayslipRecord;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use App\Queries\Payroll\MyLoansQuery;
use App\Queries\Payroll\ResolvePayslips;
use App\Services\Payroll\RateScheduleResolver;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * The spec's worked example, as real rows: 13 days, 30 min late, 120 min of
 * logged AND approved overtime, one worked regular holiday. Net = 7,787 with
 * the spec's fixed contributions (pre-2026), 7,957.25 under the 2026 formulas.
 */
function workedExample(User $guard, PayrollRun $run, User $approver): void
{
    $day = $run->start_date;

    for ($i = 0; $i < 13; $i++) {
        TimeLog::factory()->on($day->addDays($i))->create([
            'user_id' => $guard->id,
            'late_minutes' => $i === 0 ? 30 : 0,
            'overtime_minutes' => $i === 1 ? 150 : 0, // 150 logged, only 120 approved
        ]);
    }

    Holiday::create(['date' => $day->addDays(2)->toDateString(), 'name' => 'Test holiday', 'type' => 'regular']);

    $overtime = new OvertimeRequest(['date' => $day->addDay()->toDateString(), 'requested_minutes' => 150, 'reason' => 'Event']);
    $overtime->user()->associate($guard);
    $overtime->supervisor()->associate($approver);
    $overtime->forceFill(['status' => RequestStatus::Approved, 'approved_minutes' => 120])->save();
}

beforeEach(function () {
    $this->accounting = staff(Role::Accounting);
    $this->guard = employee();
    $this->run = payrollRun('2026-09-01', '2026-09-15');
    workedExample($this->guard, $this->run, staff(Role::Hr));
});

it('estimates the worked example under the 2026 rules, paying overtime only up to the approved minutes', function () {
    $payslip = app(ResolvePayslips::class)->forUser($this->run, $this->guard);

    // Gross ₱8,731. 1st cutoff of the month = half of the monthly contributions,
    // estimated on this cutoff × 2: SSS MSC ₱17,500 → ₱875/mo; PhilHealth 2.5% of
    // ₱15,860 → ₱396.50/mo; Pag-IBIG capped at ₱200/mo. ₱610 ≤ minimum wage → no tax.
    expect($payslip->isGenerated)->toBeFalse()
        ->and($payslip->overtimeMinutes)->toBe(120)
        ->and($payslip->overtimePay)->toBe(191.0)
        ->and($payslip->holidayPay)->toBe(610.0)
        ->and($payslip->grossPay())->toBe(8731.0)
        ->and($payslip->sss)->toBe(437.5)
        ->and($payslip->philhealth)->toBe(198.25)
        ->and($payslip->pagibig)->toBe(100.0)
        ->and($payslip->withholdingTax)->toBe(0.0)
        ->and($payslip->isMinimumWageEarner())->toBeTrue()
        ->and($payslip->contributions['msc'])->toBe(17500.0)
        ->and($payslip->contributions['final_cutoff'])->toBeFalse()
        ->and($payslip->netPay())->toBe(7957.25);
});

it('still reproduces the original spec example (net 7,787) on a pre-2026 schedule', function () {
    $run = payrollRun('2025-09-01', '2025-09-15');
    $guard = employee();
    workedExample($guard, $run, staff(Role::Hr));

    $payslip = app(ResolvePayslips::class)->forUser($run, $guard);

    expect($payslip->usesStatutoryFormulas())->toBeFalse()
        ->and($payslip->governmentDeductions())->toBe(906.0)
        ->and($payslip->netPay())->toBe(7787.0);
});

it('freezes a generated payslip: later rate changes do not alter it', function () {
    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting);

    config()->set('payroll.schedules.2026-01-01.daily_rate.field', 999);
    config()->set('payroll.schedules.2026-01-01.statutory.sss.employee_rate', 0.09);
    app()->forgetInstance(RateScheduleResolver::class);

    $payslip = app(ResolvePayslips::class)->forUser($this->run->fresh(), $this->guard);

    expect($payslip->isGenerated)->toBeTrue()
        ->and($payslip->netPay())->toBe(7957.25)
        ->and($payslip->contributions['sss']['employer'])->toBe(875.0) // employer share frozen too
        ->and(PayslipRecord::query()->sole()->net_pay)->toBe('7957.25');
});

it('cannot generate the same payslip twice', function () {
    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting);

    expect(fn () => app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting))
        ->toThrow(ValidationException::class);

    expect(PayslipRecord::query()->count())->toBe(1);
});

it('only generates while the run is processing', function () {
    $this->run->forceFill(['status' => PayrollRunStatus::Draft])->save();

    expect(fn () => app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting))
        ->toThrow(ValidationException::class, 'Payslips can only be generated while the run is processing.');
});

it('reduces a loan balance by one installment and switches it off when paid', function () {
    $loan = app(SaveEmployeeDeduction::class)->handle($this->guard, 'Cash advance', DeductionType::Loan, 500, 800, $this->accounting, DeductionAuthorizationBasis::Paper);

    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting);
    expect($loan->fresh())->remaining_balance->toBe('300.00')->is_active->toBeTrue();
    expect(PayslipRecord::query()->sole()->net_pay)->toBe('7457.25');

    // Three days (₱1,830): enough to cover the month's remaining contributions AND the loan.
    $next = payrollRun('2026-09-16', '2026-09-30');
    foreach (['2026-09-16', '2026-09-17', '2026-09-18'] as $day) {
        TimeLog::factory()->on(CarbonImmutable::parse($day))->create(['user_id' => $this->guard->id]);
    }
    $record = app(GeneratePayslip::class)->handle($next, $this->guard, $this->accounting);

    // Only the 300 still owed is taken, not the full 500 installment.
    expect($record->loan_deductions)->toBe('300.00')
        ->and($loan->fresh())->remaining_balance->toBe('0.00')->is_active->toBeFalse();
});

it('moves through draft → processing → approved → paid, stamping paid_at', function () {
    $run = payrollRun('2026-10-01', '2026-10-15', 'draft');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-02'))->create(['user_id' => $this->guard->id]);
    $advance = app(AdvancePayrollRun::class);

    expect($advance->handle($run, $this->accounting)->status)->toBe(PayrollRunStatus::Processing);

    // On the period's last day it can't be approved yet: attendance can still change.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 18:00'));
    expect(fn () => $advance->handle($run, $this->accounting))->toThrow(ValidationException::class, 'Available from Oct 16, 2026.');

    $this->travelTo(CarbonImmutable::parse('2026-10-16 08:00'));
    expect(fn () => $advance->handle($run, $this->accounting))->toThrow(ValidationException::class, '1 employee who worked this period has no payslip yet.');

    app(GeneratePayslip::class)->handle($run, $this->guard, $this->accounting);
    expect($advance->handle($run, $this->accounting)->status)->toBe(PayrollRunStatus::Approved);
    expect($advance->handle($run, $this->accounting)->status)->toBe(PayrollRunStatus::Paid);

    expect(PayslipRecord::query()->where('payroll_run_id', $run->id)->sole())
        ->paid_by->toBe($this->accounting->id)
        ->paid_at->not->toBeNull();
    expect(ActivityLog::query()->whereIn('event', ['payslip.generated', 'run.approved', 'run.paid'])->count())->toBe(3);
});

it('locks Approve run with the reason as a tooltip until the period has ended', function () {
    $run = payrollRun('2026-10-01', '2026-10-15', 'processing');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00'));

    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $run->id)
        ->assertSee('Available from Oct 16, 2026.')
        ->call('advanceRun')
        ->assertHasErrors(['run']);

    expect($run->fresh()->status)->toBe(PayrollRunStatus::Processing);
});

it('shows identical numbers on the payroll screen and the employee\'s own page', function () {
    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->call('viewPayslip', $this->guard->id)
        ->assertSee('₱7,957.25');

    Livewire::actingAs($this->guard)->test(MyPayslips::class)
        ->call('viewRun', $this->run->id)
        ->assertSee('₱7,957.25');
});

it('generates everyone who worked with one click, and re-checks the gate on every write', function () {
    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->call('generateAll')
        ->assertDispatched('notify', message: 'Generated 1 payslip(s).');

    $hr = staff(Role::Hr);
    Livewire::actingAs($hr)->test(Payroll::class)->call('generateAll')->assertForbidden();
});

it('hides draft runs from employees', function () {
    payrollRun('2026-10-01', '2026-10-15', 'draft');

    Livewire::actingAs($this->guard)->test(MyPayslips::class)
        ->assertSee($this->run->periodLabel())
        ->assertDontSee('October 1–15, 2026');
});

it('lists every late day behind the payslip\'s late line, with the disclaimer', function () {
    $lateDay = $this->run->start_date;

    Livewire::actingAs($this->guard)->test(MyPayslips::class)
        ->call('viewRun', $this->run->id)
        ->assertSee('Late (30 min, 1 day)')
        ->assertSee($lateDay->format('D, M j'))
        ->assertSee('9:00 AM')
        ->assertSee(config('payroll.disclaimer'));
});

it('records who opened whose payslip in the audit log', function () {
    Livewire::actingAs($this->guard)->test(MyPayslips::class)->call('viewRun', $this->run->id);
    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->call('viewPayslip', $this->guard->id);

    $views = ActivityLog::query()->where('event', 'payslip.viewed')->orderBy('id')->get();

    expect($views)->toHaveCount(2)
        ->and($views[0]->causer_id)->toBe($this->guard->id)
        ->and($views[0]->description)->toContain('their own payslip')
        ->and($views[1]->causer_id)->toBe($this->accounting->id)
        ->and($views[1]->properties)->toBe(['employee_id' => $this->guard->id]);
});

it('watermarks the payslip with the viewer and blanks it when printed', function () {
    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->call('viewPayslip', $this->guard->id)
        ->assertSeeHtml('data-sensitive')
        ->assertSeeHtml(rawurlencode(e($this->accounting->name)));
});

it('explains why Approve run is locked, and unlocks it once every payslip exists', function () {
    $page = Livewire::actingAs($this->accounting)->test(Payroll::class)->call('viewRun', $this->run->id);

    $page->assertSeeHtml('role="tooltip"')
        ->assertSee('1 employee who worked this period has no payslip yet')
        // Locked means locked: disabled, and no action attached at all.
        ->assertSeeHtml('disabled aria-disabled="true"')
        ->assertDontSeeHtml('wire:click="advanceRun" wire:confirm="Approve this run?');

    $page->call('generateAll')
        ->assertDontSee('has no payslip yet')
        ->assertSeeHtml('wire:click="advanceRun"');
});

it('shows no figures, and never a negative net pay, for someone with no attendance', function () {
    $absent = employee();

    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->call('viewPayslip', $absent->id)
        ->assertSee('No attendance in this period, so no payslip will be generated.')
        ->assertSee('No earnings or deductions for this period')
        ->assertDontSee('Total deductions')
        ->assertDontSee('Net pay')
        // An amount ("-906.00"), not just "-906": random ids on the page (UUIDs) can contain that.
        ->assertDontSee('-906.');
});

// The never-below-₱0 mechanism, on a pre-2026 run: with the old fixed ₱906 of
// contributions one day's pay runs out. (Under the 2026 formulas a short
// first cutoff deducts only half the month, so it rarely does.)
it('freezes a 1-day payslip at ₱0 net and deducts the rest first on the next payslip', function () {
    $guard = employee();
    $october = payrollRun('2025-10-01', '2025-10-15');
    TimeLog::factory()->on(CarbonImmutable::parse('2025-10-02'))->create(['user_id' => $guard->id]);

    $first = app(GeneratePayslip::class)->handle($october, $guard, $this->accounting);

    expect((float) $first->net_pay)->toBe(0.0)
        ->and((float) $first->total_deductions)->toBe(610.0);

    $owed = PayrollCarryover::query()->where('user_id', $guard->id)->orderBy('id')->get();
    expect($owed->pluck('label')->all())->toBe(['PhilHealth (from October 1–15, 2025)', 'Pag-IBIG (from October 1–15, 2025)'])
        ->and($owed->sum(fn ($row) => (float) $row->amount))->toBe(296.0);

    // The employee sees what wasn't taken, and why.
    Livewire::actingAs($guard)->test(MyPayslips::class)
        ->call('viewRun', $october->id)
        ->assertSee("Not deducted: the pay for this period wasn't enough", false)
        ->assertSee('carried to the next payslip');

    // Next period, a full fortnight: the ₱296 comes off first, and is then settled.
    $late = payrollRun('2025-10-16', '2025-10-31');
    for ($day = 16; $day <= 28; $day++) {
        TimeLog::factory()->on(CarbonImmutable::parse("2025-10-{$day}"))->create(['user_id' => $guard->id]);
    }

    $second = app(GeneratePayslip::class)->handle($late, $guard, $this->accounting);

    expect((float) $second->carryover_deductions)->toBe(296.0)
        ->and((float) $second->net_pay)->toBe(13 * 610 - 906 - 296.0)
        ->and(PayrollCarryover::query()->whereNull('settled_in_payroll_run_id')->count())->toBe(0);
});

it('lowers a loan balance only by what was actually deducted', function () {
    $guard = employee();
    $loan = app(SaveEmployeeDeduction::class)->handle($guard, 'Cash advance', DeductionType::CashAdvance, 500, 2000, $this->accounting, DeductionAuthorizationBasis::Paper);
    $october = payrollRun('2025-10-01', '2025-10-15'); // pre-2026: fixed ₱906 of contributions
    TimeLog::factory()->on(CarbonImmutable::parse('2025-10-02'))->create(['user_id' => $guard->id]);
    TimeLog::factory()->on(CarbonImmutable::parse('2025-10-03'))->create(['user_id' => $guard->id]);

    app(GeneratePayslip::class)->handle($october, $guard, $this->accounting);

    // ₱1,220 − ₱906 contributions leaves ₱314 for the ₱500 installment.
    expect((float) $loan->fresh()?->remaining_balance)->toBe(2000 - 314.0)
        ->and(PayrollCarryover::query()->count())->toBe(0); // loans aren't carried; the balance holds the rest
});

it('balances the month on its last cutoff: monthly contribution minus what the first cutoff took', function () {
    // beforeEach: Sept 1–15, 13 days (₱8,731 gross). Generate it, then a full second half.
    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting);
    $second = payrollRun('2026-09-16', '2026-09-30');
    foreach (range(16, 28) as $day) {
        TimeLog::factory()->on(CarbonImmutable::parse("2026-09-{$day}"))->create(['user_id' => $this->guard->id]);
    }

    $payslip = app(ResolvePayslips::class)->forUser($second, $this->guard);

    // Month: SSS on ₱8,731 + ₱7,930 = ₱16,661 → MSC ₱16,500 → ₱825, minus ₱437.50 already.
    // PhilHealth 2.5% of ₱15,860 = ₱396.50, minus ₱198.25. Pag-IBIG ₱200, minus ₱100.
    expect($payslip->contributions['final_cutoff'])->toBeTrue()
        ->and($payslip->contributions['msc'])->toBe(16500.0)
        ->and($payslip->sss)->toBe(387.5)
        ->and($payslip->philhealth)->toBe(198.25)
        ->and($payslip->pagibig)->toBe(100.0)
        ->and($payslip->contributions['sss']['employer'])->toBe(1650.0 - 875.0);
});

it('makes the month\'s last cutoff wait for the first one to be generated', function () {
    $second = payrollRun('2026-09-16', '2026-09-30');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-09-17'))->create(['user_id' => $this->guard->id]);

    expect(fn () => app(GeneratePayslip::class)->handle($second, $this->guard, $this->accounting))
        ->toThrow(ValidationException::class, 'September 1–15, 2026 payslip first');

    // "Generate all" skips them instead of failing.
    expect(app(GenerateRunPayslips::class)->handle($second, $this->accounting))->toBe(0);

    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting);
    expect(app(GenerateRunPayslips::class)->handle($second, $this->accounting))->toBe(1);
});

it('treats guards at or below the minimum wage as tax exempt, without telling the employer what to pay', function () {
    // The ₱610 test default is < ₱695: tax exempt. The rate itself is the employer's call: no banner.
    expect(app(ResolvePayslips::class)->forUser($this->run, $this->guard)->isMinimumWageEarner())->toBeTrue();
    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->assertDontSee('below the minimum wage');

    // Accounting sets every employee's real rate on Daily Rates; the payslip follows it.
    foreach (EmployeeProfile::query()->get() as $profile) {
        app(SetEmployeeRate::class)->handle($profile, 800, CarbonImmutable::parse('2026-01-01'), 'Contract rate', $this->accounting);
    }

    $payslip = app(ResolvePayslips::class)->forUser($this->run, $this->guard);
    expect($payslip->dailyRate)->toBe(800.0)
        ->and($payslip->basicPay)->toBe(13 * 800.0)
        ->and($payslip->isMinimumWageEarner())->toBeFalse();
});

it('exports employee and employer shares for the remittances', function () {
    $csv = Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->call('exportCsv')
        ->effects['download']['content'] ?? '';

    expect(base64_decode($csv))->toContain('SSS (ER)')->toContain('437.50')->toContain('875.00');
});

it('keeps loans read-only on a generated or paid payslip, and hides the wage banner on closed runs', function () {
    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting);

    $page = Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->call('viewPayslip', $this->guard->id)
        ->assertSee('This payslip is generated and frozen. Loans apply to future payslips')
        ->assertDontSeeHtml('wire:submit="addDeduction"');

    // Locked on the server too, not only in the view.
    $page->set('deductionLabel', 'Sneaky loan')->set('deductionInstallment', '100')
        ->call('addDeduction')
        ->assertHasErrors('deductionLabel');
    expect(EmployeeDeduction::query()->count())->toBe(0);

    // An open payslip on the same run still allows it.
    $other = employee();
    TimeLog::factory()->on(CarbonImmutable::parse('2026-09-03'))->create(['user_id' => $other->id]);
    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->call('viewPayslip', $other->id)
        ->assertSeeHtml('wire:submit="addDeduction"');

    // Approved / paid: closed, so no banner either (₱610 < minimum would otherwise show it).
    $this->run->forceFill(['status' => PayrollRunStatus::Paid])->save();
    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->assertDontSee('paid below the minimum wage')
        ->call('viewPayslip', $other->id)
        ->assertSee('This run is Paid, so its payslips are final');
});

it('pays night differential from the hours actually clocked 10 PM–6 AM, frozen on the payslip', function () {
    $night = Shift::factory()->create(['name' => 'Night post', 'start_time' => '18:00', 'end_time' => '06:00', 'grace_minutes' => 0]);
    $guard = employee(['shift_id' => $night->id]);
    $run = payrollRun('2026-10-01', '2026-10-15');

    // Two 18:00–06:00 duties: 8 night hours each (22:00–06:00), not 12.
    foreach (['2026-10-05', '2026-10-06'] as $date) {
        $day = CarbonImmutable::parse($date);
        TimeLog::factory()->on($day)->create(['user_id' => $guard->id, 'shift_id' => $night->id, 'clock_in_at' => $day->setTime(18, 0), 'clock_out_at' => $day->addDay()->setTime(6, 0)]);
    }

    $payslip = app(ResolvePayslips::class)->forUser($run, $guard);
    // 960 min × (₱610 ÷ 480) × 10% = ₱122.
    expect($payslip->nightMinutes)->toBe(960)->and($payslip->nightDifferential)->toBe(122.0);

    $record = app(GeneratePayslip::class)->handle($run, $guard, $this->accounting);
    expect($record->night_minutes)->toBe(960)->and($record->night_differential)->toBe('122.00');

    Livewire::actingAs($guard)->test(MyPayslips::class)->call('viewRun', $run->id)->assertSee('16 hrs, 10 PM–6 AM');
});

it('shows the employee their loans: what is left, how it was authorized, and what each payslip took', function () {
    $loan = app(SaveEmployeeDeduction::class)->handle($this->guard, 'Emergency cash advance', DeductionType::CashAdvance, 500, 800, $this->accounting, DeductionAuthorizationBasis::Paper);
    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting);

    $loans = app(MyLoansQuery::class)->handle($this->guard);
    expect($loans)->toHaveCount(1)
        ->and($loans[0]['paid'])->toBe(500.0)
        ->and($loans[0]['payments'][0]['balance_after'])->toBe(300.0);

    Livewire::actingAs($this->guard)->test(MyLoans::class)
        ->assertSee('Emergency cash advance')->assertSee('₱300.00')->assertSee('left')
        ->assertSee('You signed an authorization on paper')->assertSee('Being deducted')->assertSee('1 payslip · ₱500.00 taken');
    expect(collect(Navigation::for($this->guard))->pluck('items')->flatten(1)->pluck('route'))->toContain('loans');
});
