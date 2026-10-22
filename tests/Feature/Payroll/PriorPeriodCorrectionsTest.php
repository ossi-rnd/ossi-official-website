<?php

use App\Actions\Payroll\AcceptPriorPeriodCorrection;
use App\Actions\Payroll\AdvancePayrollRun;
use App\Actions\Payroll\DetectPriorPeriodCorrections;
use App\Actions\Payroll\DismissPriorPeriodCorrection;
use App\Actions\Payroll\GeneratePayslip;
use App\Actions\Payroll\GenerateRunPayslips;
use App\Actions\Payroll\RemovePayrollAdjustment;
use App\Enums\AdjustmentCategory;
use App\Enums\Role;
use App\Livewire\Payroll\PriorPeriodCorrections;
use App\Models\PayrollRun;
use App\Models\PriorPeriodCorrection;
use App\Models\TimeLog;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Pay that changed after a cut-off was paid (here: a day recorded by hand after
// the payslip was frozen) is suggested on the next run, for Accounting to accept
// or dismiss. Nothing is silently lost, and nothing is paid twice.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00'));
    $this->accounting = staff(Role::Accounting);
    $this->guard = employee();

    // Oct 1–15: five days worked, payslip frozen, run approved.
    $this->paid = payrollRun('2026-10-01', '2026-10-15');
    foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $date) {
        TimeLog::factory()->on(CarbonImmutable::parse($date))->create(['user_id' => $this->guard->id]);
    }
    $this->frozen = app(GeneratePayslip::class)->handle($this->paid, $this->guard, $this->accounting);
    $this->paid->forceFill(['status' => 'approved'])->save();

    // Oct 16–31 is open, and the guard worked on it.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
    $this->open = payrollRun('2026-10-16', '2026-10-31');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-16'))->create(['user_id' => $this->guard->id]);
});

/** Oct 12 recorded after the Oct 1–15 payslip was frozen (the phone was broken). */
function recordedLate(object $test): void
{
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-12'))->create(['user_id' => $test->guard->id]);
}

it('suggests nothing when no earlier pay changed', function () {
    expect(app(DetectPriorPeriodCorrections::class)->handle($this->open))->toBe(0)
        ->and(PriorPeriodCorrection::query()->count())->toBe(0);
});

it('suggests a day recorded by hand after the cut-off was paid, and holds the payslip until it\'s decided', function () {
    recordedLate($this);

    expect(app(DetectPriorPeriodCorrections::class)->handle($this->open))->toBe(1);
    $correction = PriorPeriodCorrection::query()->sole();
    expect((float) $correction->amount)->toBe((float) $this->frozen->daily_rate)
        ->and($correction->from_payroll_run_id)->toBe($this->paid->id)
        ->and($correction->summary())->toBe('1 more day worked');

    // The payslip waits; Generate all skips them rather than failing.
    expect(fn () => app(GeneratePayslip::class)->handle($this->open, $this->guard, $this->accounting))->toThrow(ValidationException::class, 'changed after it was paid');
    expect(app(GenerateRunPayslips::class)->handle($this->open, $this->accounting))->toBe(0);
});

it('pays it on this payslip once accepted, as a taxable correction, and never suggests it twice', function () {
    recordedLate($this);
    app(DetectPriorPeriodCorrections::class)->handle($this->open);

    $accepted = app(AcceptPriorPeriodCorrection::class)->handle(PriorPeriodCorrection::query()->sole(), $this->accounting);
    expect($accepted->status)->toBe(PriorPeriodCorrection::ACCEPTED)
        ->and($accepted->adjustment?->category)->toBe(AdjustmentCategory::Correction)
        ->and($accepted->adjustment?->reason)->toStartWith('Prior period ')->toEndWith(': 1 more day worked');

    $payslip = app(GeneratePayslip::class)->handle($this->open, $this->guard, $this->accounting);
    expect((float) $payslip->adjustment_earnings)->toBe((float) $this->frozen->daily_rate);

    // The next run's check: already settled.
    $next = payrollRun('2026-11-01', '2026-11-15');
    $this->open->forceFill(['status' => 'approved'])->save();
    TimeLog::factory()->on(CarbonImmutable::parse('2026-11-03'))->create(['user_id' => $this->guard->id]);
    expect(app(DetectPriorPeriodCorrections::class)->handle($next))->toBe(0);
});

it('dismisses with a reason for good, but a removed adjustment makes it owed again', function () {
    recordedLate($this);
    $detect = app(DetectPriorPeriodCorrections::class);
    $detect->handle($this->open);

    expect(fn () => app(DismissPriorPeriodCorrection::class)->handle(PriorPeriodCorrection::query()->sole(), $this->accounting, ''))->toThrow(ValidationException::class, 'Say why');
    app(DismissPriorPeriodCorrection::class)->handle(PriorPeriodCorrection::query()->sole(), $this->accounting, 'Paid in cash on Oct 18');
    expect($detect->handle($this->open))->toBe(0);

    // Accepted, then the adjustment removed: the day is owed again.
    PriorPeriodCorrection::query()->delete();
    $detect->handle($this->open);
    $accepted = app(AcceptPriorPeriodCorrection::class)->handle(PriorPeriodCorrection::query()->sole(), $this->accounting);
    app(RemovePayrollAdjustment::class)->handle($accepted->adjustment, $this->accounting);
    expect($detect->handle($this->open))->toBe(1);
});

it('is listed on the open run for Accounting to accept or dismiss', function () {
    recordedLate($this);
    app(DetectPriorPeriodCorrections::class)->handle($this->open);
    $correction = PriorPeriodCorrection::query()->sole();

    Livewire::actingAs($this->accounting)->test(PriorPeriodCorrections::class, ['runId' => $this->open->id])
        ->assertSee('Corrections from earlier cut-offs')
        ->assertSee('1 more day worked')
        ->call('accept', $correction->id)
        ->assertDispatched('adjustments-changed')
        ->assertSee('On this payslip');

    $this->actingAs(staff(Role::Hr))->get(route('payroll', ['run' => $this->open->id]))->assertForbidden();
});

it('lists pay that changed as soon as a run starts processing', function () {
    recordedLate($this);
    $draft = PayrollRun::query()->findOrFail($this->open->id);
    $draft->forceFill(['status' => 'draft'])->save();

    app(AdvancePayrollRun::class)->handle($draft, $this->accounting);

    expect(PriorPeriodCorrection::query()->where('payroll_run_id', $draft->id)->suggested()->count())->toBe(1);
});
