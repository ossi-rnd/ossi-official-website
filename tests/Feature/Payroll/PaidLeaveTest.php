<?php

use App\Actions\Payroll\AcceptPaidLeave;
use App\Actions\Payroll\DetectPaidLeave;
use App\Actions\Payroll\DismissPaidLeave;
use App\Actions\Payroll\GeneratePayslip;
use App\Actions\Payroll\GenerateRunPayslips;
use App\Enums\AdjustmentCategory;
use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Livewire\Payroll\PaidLeaveSuggestions;
use App\Models\LeaveRequest;
use App\Models\PaidLeavePayment;
use App\Models\PayrollAdjustment;
use App\Models\PayslipRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Leave the law pays (paternity, solo parent, VAWC, special leave for women at the
// daily rate; maternity as the SSS benefit advance) is suggested on the payroll run
// for Accounting to check and accept, never added silently.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
    $this->accounting = staff(Role::Accounting);
    $this->guard = employee();
    $this->run = payrollRun('2026-10-16', '2026-10-31');
});

function approvedLeave(User $user, LeaveType $type, string $start, string $end): LeaveRequest
{
    $leave = $user->leaveRequests()->create(['type' => $type, 'start_date' => $start, 'end_date' => $end, 'reason' => 'As the law allows.']);
    $leave->forceFill(['status' => RequestStatus::Approved, 'stage' => null])->save();

    return $leave;
}

it('suggests paid paternity leave at the daily rate, holds the payslip until it\'s decided, and pays it even with no attendance', function () {
    approvedLeave($this->guard, LeaveType::Paternity, '2026-10-20', '2026-10-22');

    expect(app(DetectPaidLeave::class)->handle($this->run))->toBe(1);
    $suggestion = PaidLeavePayment::query()->sole();
    $rate = (float) $suggestion->daily_rate;
    expect($suggestion->days)->toBe(3)->and($rate)->toBeGreaterThan(0)
        ->and((float) $suggestion->amount)->toBe(round(3 * $rate, 2));

    expect(fn () => app(GeneratePayslip::class)->handle($this->run, $this->guard, $this->accounting))->toThrow(ValidationException::class, 'paid leave in this cut-off');

    // Accounting pays 2 of the 3 days (a rest day fell inside it).
    app(AcceptPaidLeave::class)->handle($suggestion, $this->accounting, days: 2);
    $adjustment = PayrollAdjustment::query()->sole();
    expect($adjustment->category)->toBe(AdjustmentCategory::PaidLeave)
        ->and((float) $adjustment->amount)->toBe(round(2 * $rate, 2))
        ->and($adjustment->category->isTaxable())->toBeTrue();

    // No time logs at all, and still generated: "Generate all" includes them too.
    expect(app(GenerateRunPayslips::class)->handle($this->run, $this->accounting))->toBe(1);
    expect((float) PayslipRecord::query()->sole()->adjustment_earnings)->toBe(round(2 * $rate, 2));
});

it('caps it at what the law gives across cut-offs, and never suggests a decided one again', function () {
    approvedLeave($this->guard, LeaveType::Paternity, '2026-10-27', '2026-11-05'); // 10 days; the law gives 7

    app(DetectPaidLeave::class)->handle($this->run);
    app(AcceptPaidLeave::class)->handle(PaidLeavePayment::query()->sole(), $this->accounting); // Oct 27–31: 5 days

    $next = payrollRun('2026-11-01', '2026-11-15');
    app(DetectPaidLeave::class)->handle($next);
    $second = PaidLeavePayment::query()->where('payroll_run_id', $next->id)->sole();
    expect($second->days)->toBe(2); // 7 − 5

    expect(fn () => app(DismissPaidLeave::class)->handle($second, $this->accounting, 'no'))->toThrow(ValidationException::class, 'why');
    app(DismissPaidLeave::class)->handle($second, $this->accounting, 'Paid in cash on Nov 3.');
    app(DetectPaidLeave::class)->handle($next);
    expect(PaidLeavePayment::query()->where('payroll_run_id', $next->id)->count())->toBe(1);
});

it('asks Accounting for the SSS maternity benefit instead of computing it', function () {
    approvedLeave($this->guard, LeaveType::Maternity, '2026-10-20', '2026-12-31');
    app(DetectPaidLeave::class)->handle($this->run);
    $suggestion = PaidLeavePayment::query()->sole();
    expect($suggestion->amount)->toBeNull()->and($suggestion->days)->toBe(12);

    expect(fn () => app(AcceptPaidLeave::class)->handle($suggestion, $this->accounting))->toThrow(ValidationException::class, 'SSS maternity benefit');
    app(AcceptPaidLeave::class)->handle($suggestion, $this->accounting, sssAmount: 15000);
    $adjustment = PayrollAdjustment::query()->sole();
    expect($adjustment->category)->toBe(AdjustmentCategory::Allowance)->and($adjustment->reason)->toContain('SSS maternity benefit advance');
});

it('shows on the payroll run and is decided from there; vacation leave isn\'t suggested', function () {
    approvedLeave($this->guard, LeaveType::SoloParent, '2026-10-21', '2026-10-21');
    approvedLeave(employee(), LeaveType::Vacation, '2026-10-21', '2026-10-21');

    $page = Livewire::actingAs($this->accounting)->test(PaidLeaveSuggestions::class, ['runId' => $this->run->id])
        ->assertSee('Paid leave this cut-off')->assertSee('Solo parent leave (RA 11861)');
    expect(PaidLeavePayment::query()->count())->toBe(1);

    $page->call('accept', PaidLeavePayment::query()->sole()->id)->assertHasNoErrors()->assertSee('On this payslip');
});
