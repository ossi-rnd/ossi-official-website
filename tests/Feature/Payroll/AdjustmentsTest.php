<?php

use App\Actions\Payroll\AddPayrollAdjustment;
use App\Actions\Payroll\GeneratePayslip;
use App\Data\PayslipBreakdown;
use App\Enums\AdjustmentCategory;
use App\Enums\AdjustmentStatus;
use App\Enums\Role;
use App\Livewire\Admin\Payroll;
use App\Livewire\Payroll\MyPayslips;
use App\Livewire\Payroll\PayslipAdjustments;
use App\Models\ActivityLog;
use App\Models\PayrollAdjustment;
use App\Models\PayslipRecord;
use App\Models\TimeLog;
use App\Notifications\PayrollAdjustmentAwaitingApproval;
use App\Queries\Payroll\ResolvePayslips;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->accounting = staff(Role::Accounting);
    $this->superAdmin = staff(Role::SuperAdmin);
    $this->guard = employee();
    $this->run = payrollRun('2026-10-01', '2026-10-15');

    foreach (range(1, 10) as $day) {
        TimeLog::factory()->on(CarbonImmutable::parse("2026-10-{$day}"))->create(['user_id' => $this->guard->id]);
    }
});

function adjust(mixed $as, mixed $run, mixed $employee, array $form): Testable
{
    $panel = Livewire::actingAs($as)->test(PayslipAdjustments::class, ['runId' => $run->id, 'userId' => $employee->id]);

    foreach ($form as $field => $value) {
        $panel->set($field, $value);
    }

    return $panel->call('add');
}

it('adds a small correction to the payslip at once, as its own line with the reason', function () {
    $before = app(ResolvePayslips::class)->forUser($this->run, $this->guard)->grossPay();

    adjust($this->accounting, $this->run, $this->guard, [
        'adjustmentCategory' => 'correction', 'adjustmentDirection' => 'add', 'adjustmentAmount' => '610', 'adjustmentReason' => 'Oct 11 clock-in missed, confirmed by supervisor',
    ])->assertHasNoErrors();

    // A correction is pay for work, so it also counts toward SSS (the net rises a little less than ₱610).
    $payslip = app(ResolvePayslips::class)->forUser($this->run, $this->guard);
    expect(PayrollAdjustment::query()->sole()->status)->toBe(AdjustmentStatus::Approved)
        ->and($payslip->grossPay())->toBe($before + 610)
        ->and($payslip->contributions['sss']['base'])->toBe(6710.0)
        ->and($payslip->basicPay)->toBe(6100.0); // the calculated figures are untouched

    Livewire::actingAs($this->guard)->test(MyPayslips::class)
        ->call('viewRun', $this->run->id)
        ->assertSee('Correction: Oct 11 clock-in missed, confirmed by supervisor');

    expect(ActivityLog::query()->where('event', 'adjustment.added')->count())->toBe(1);
});

it('taxes corrections but not allowances for office staff', function () {
    // 13 office days (₱11,700, ₱900 > minimum wage → taxed). After SSS ₱587.50,
    // PhilHealth ₱292.50 and Pag-IBIG ₱100, taxable ₱10,720 → 15% over ₱10,417 = ₱45.45.
    $clerk = staff(Role::Employee);
    foreach (range(1, 13) as $day) {
        TimeLog::factory()->on(CarbonImmutable::parse("2026-10-{$day}"))->create(['user_id' => $clerk->id]);
    }
    expect(app(ResolvePayslips::class)->forUser($this->run, $clerk)->withholdingTax)->toBe(45.45);

    // Allowance: not taxable, not part of the SSS base: tax unchanged.
    app(AddPayrollAdjustment::class)->handle($this->run, $clerk, AdjustmentCategory::Allowance, 500, 'Transport allowance', $this->accounting);
    expect(app(ResolvePayslips::class)->forUser($this->run, $clerk)->withholdingTax)->toBe(45.45);

    // Correction +₱900: taxable ₱12,600, SSS rises to ₱625 → taxable ₱11,582.50 → ₱174.83.
    app(AddPayrollAdjustment::class)->handle($this->run, $clerk, AdjustmentCategory::Correction, 900, 'Unpaid day Oct 3', $this->accounting);
    expect(app(ResolvePayslips::class)->forUser($this->run, $clerk)->withholdingTax)->toBe(174.83);
});

it('applies even a large adjustment from Accounting at once by default: no Super Admin needed', function () {
    $before = app(ResolvePayslips::class)->forUser($this->run, $this->guard)->netPay();

    adjust($this->accounting, $this->run, $this->guard, [
        'adjustmentCategory' => 'refund', 'adjustmentDirection' => 'add', 'adjustmentAmount' => '25000', 'adjustmentReason' => 'Uniform and equipment deposit refund',
    ])->assertHasNoErrors()->assertDontSee('Awaiting approval')->assertDontSee("needs a Super Admin's approval", false);

    expect(PayrollAdjustment::query()->sole()->status)->toBe(AdjustmentStatus::Approved)
        ->and(app(ResolvePayslips::class)->forUser($this->run, $this->guard)->netPay())->toBe($before + 25000);
    Notification::assertNothingSent();
    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting); // nothing blocks it
});

it('can still hold large adjustments for a second person when a threshold is configured', function () {
    config(['payroll.adjustments.approval_threshold' => 5000]);
    $before = app(ResolvePayslips::class)->forUser($this->run, $this->guard)->netPay();

    adjust($this->accounting, $this->run, $this->guard, [
        'adjustmentCategory' => 'refund', 'adjustmentDirection' => 'add', 'adjustmentAmount' => '6000', 'adjustmentReason' => 'Uniform deposit refund',
    ])->assertHasNoErrors()->assertSee('Awaiting approval');

    $adjustment = PayrollAdjustment::query()->sole();
    expect($adjustment->status)->toBe(AdjustmentStatus::Pending)
        ->and(app(ResolvePayslips::class)->forUser($this->run, $this->guard)->netPay())->toBe($before); // not applied yet
    Notification::assertSentTo($this->superAdmin, PayrollAdjustmentAwaitingApproval::class);

    expect(fn () => app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting))
        ->toThrow(ValidationException::class, 'awaiting approval');

    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)
        ->assertSee("awaiting a Super Admin's approval", false)
        ->call('generateAll')
        ->assertDispatched('notify', message: 'Every employee who worked this period already has a payslip. 1 skipped: adjustment awaiting approval.');

    // Accounting can't approve; the Super Admin can.
    Livewire::actingAs($this->accounting)->test(PayslipAdjustments::class, ['runId' => $this->run->id, 'userId' => $this->guard->id])
        ->call('approve', $adjustment->id)->assertForbidden();
    Livewire::actingAs($this->superAdmin)->test(PayslipAdjustments::class, ['runId' => $this->run->id, 'userId' => $this->guard->id])
        ->call('approve', $adjustment->id)->assertHasNoErrors();

    expect(app(ResolvePayslips::class)->forUser($this->run, $this->guard)->netPay())->toBe($before + 6000);
    expect(ActivityLog::query()->where('event', 'adjustment.approved')->count())->toBe(1);
});

it('never lets the creator approve their own adjustment (when the check is on)', function () {
    config(['payroll.adjustments.approval_threshold' => 5000]);
    $otherAdmin = staff(Role::SuperAdmin);
    $adjustment = app(AddPayrollAdjustment::class)->handle($this->run, $this->guard, AdjustmentCategory::Refund, 7000, 'Big refund', $this->accounting);

    // A Super Admin who also creates one gets it approved at once (no one is stuck)…
    $own = app(AddPayrollAdjustment::class)->handle($this->run, $this->guard, AdjustmentCategory::Allowance, 8000, 'Hazard pay', $otherAdmin);
    expect($own->status)->toBe(AdjustmentStatus::Approved);

    // …but nobody approves an adjustment they made while it is pending.
    $adjustment->forceFill(['created_by' => $otherAdmin->id])->save();
    Livewire::actingAs($otherAdmin)->test(PayslipAdjustments::class, ['runId' => $this->run->id, 'userId' => $this->guard->id])
        ->call('approve', $adjustment->id)->assertForbidden();
});

it('rejects with a reason, and a rejected adjustment never reaches the payslip (when the check is on)', function () {
    config(['payroll.adjustments.approval_threshold' => 5000]);
    $before = app(ResolvePayslips::class)->forUser($this->run, $this->guard)->netPay();
    $adjustment = app(AddPayrollAdjustment::class)->handle($this->run, $this->guard, AdjustmentCategory::Refund, 7000, 'Refund', $this->accounting);

    Livewire::actingAs($this->superAdmin)->test(PayslipAdjustments::class, ['runId' => $this->run->id, 'userId' => $this->guard->id])
        ->call('startReject', $adjustment->id)
        ->call('reject')->assertHasErrors(['reviewNote' => 'required'])
        ->set('reviewNote', 'No receipt attached')
        ->call('reject')->assertHasNoErrors();

    expect($adjustment->fresh()?->status)->toBe(AdjustmentStatus::Rejected)
        ->and(app(ResolvePayslips::class)->forUser($this->run, $this->guard)->netPay())->toBe($before);
    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting); // no longer blocked
});

it('deducts a penalty but never below zero, carrying the rest', function () {
    $net = app(ResolvePayslips::class)->forUser($this->run, $this->guard)->netPay();

    app(AddPayrollAdjustment::class)->handle($this->run, $this->guard, AdjustmentCategory::Penalty, -($net - 100), 'Lost radio (signed agreement)', $this->superAdmin);
    app(AddPayrollAdjustment::class)->handle($this->run, $this->guard, AdjustmentCategory::Penalty, -300, 'Lost flashlight (signed agreement)', $this->superAdmin);

    $payslip = app(ResolvePayslips::class)->forUser($this->run, $this->guard);
    expect($payslip->netPay())->toBe(0.0)
        ->and($payslip->shortfallLines)->toBe([['label' => 'Penalty: Lost flashlight (signed agreement)', 'amount' => 200.0, 'carries' => true]]);
});

it('checks direction, reason and timing in the Action itself', function () {
    $add = app(AddPayrollAdjustment::class);

    expect(fn () => $add->handle($this->run, $this->guard, AdjustmentCategory::Penalty, 100, 'Not a deduction', $this->accounting))->toThrow(ValidationException::class)
        ->and(fn () => $add->handle($this->run, $this->guard, AdjustmentCategory::Allowance, -100, 'Not an addition', $this->accounting))->toThrow(ValidationException::class)
        ->and(fn () => $add->handle($this->run, $this->guard, AdjustmentCategory::Correction, 100, 'x', $this->accounting))->toThrow(ValidationException::class);

    // No attendance: there will be no payslip to adjust.
    expect(fn () => $add->handle($this->run, employee(), AdjustmentCategory::Correction, 100, 'Missed day', $this->accounting))->toThrow(ValidationException::class);

    // Generated: frozen, so it goes on the next payroll.
    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting);
    expect(fn () => $add->handle($this->run, $this->guard, AdjustmentCategory::Correction, 100, 'Too late now', $this->accounting))->toThrow(ValidationException::class, 'next payroll');
});

it('freezes adjustments with the payslip and stops removals after generation', function () {
    $panel = adjust($this->accounting, $this->run, $this->guard, [
        'adjustmentCategory' => 'allowance', 'adjustmentDirection' => 'add', 'adjustmentAmount' => '250', 'adjustmentReason' => 'Meal allowance for the event',
    ]);
    $adjustment = PayrollAdjustment::query()->sole();

    app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting);

    $record = PayslipRecord::query()->sole();
    expect($record->adjustment_earnings)->toBe('250.00')
        ->and(PayslipBreakdown::fromRecord($record)->adjustmentLines)->toBe([['adjustment_id' => $adjustment->id, 'label' => 'Allowance: Meal allowance for the event', 'amount' => 250.0]]);

    $panel->call('remove', $adjustment->id)->assertHasErrors('adjustmentAmount');
    expect(PayrollAdjustment::query()->count())->toBe(1);
});

it('removes an adjustment before generation, keeping it in the audit log', function () {
    $adjustment = app(AddPayrollAdjustment::class)->handle($this->run, $this->guard, AdjustmentCategory::Allowance, 100, 'Wrong employee', $this->accounting);

    Livewire::actingAs($this->accounting)->test(PayslipAdjustments::class, ['runId' => $this->run->id, 'userId' => $this->guard->id])
        ->call('remove', $adjustment->id)->assertHasNoErrors();

    expect(PayrollAdjustment::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'adjustment.removed')->sole()->properties['old']['reason'])->toBe('Wrong employee');
});

it('keeps adjustments to people who manage payroll', function () {
    Livewire::actingAs(staff(Role::Hr))->test(PayslipAdjustments::class, ['runId' => $this->run->id, 'userId' => $this->guard->id])->assertForbidden();
});
