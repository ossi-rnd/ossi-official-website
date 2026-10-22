<?php

use App\Actions\Auth\ClaimEmployeeAccount;
use App\Actions\FinalPay\PrepareFinalPay;
use App\Actions\FinalPay\ReturnFinalPay;
use App\Actions\FinalPay\SignFinalPay;
use App\Actions\FinalPay\SubmitFinalPay;
use App\Actions\FinalPay\UpdateFinalPayAdjustments;
use App\Actions\Payroll\EnrollCashBond;
use App\Actions\Payroll\GeneratePayslip;
use App\Actions\Payroll\RecordCashBondEntry;
use App\Actions\Resignations\AcceptResignation;
use App\Actions\Resignations\FileResignation;
use App\Enums\ClearanceItem;
use App\Enums\DeductionType;
use App\Enums\FinalPayStatus;
use App\Enums\ResignationReason;
use App\Enums\Role;
use App\Models\CashBondEntry;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeProfile;
use App\Models\Resignation;
use App\Models\TimeLog;
use App\Notifications\FinalPayNotice;
use App\Queries\Payroll\CashBondBalanceQuery;
use App\Queries\Payroll\ThirteenthMonthBasisQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

// Final pay for someone who resigned: + pro-rated 13th month + cash bond refund
// ± adjustments − what's owed (with consent), signed by Accounting, then the Executive.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
    Notification::fake();

    $this->guard = employee();
    $this->accounting = staff(Role::Accounting);
    $this->hr = staff(Role::Hr);
    $this->opsHq = staff(Role::OperationsHq);
    $this->admin = staff(Role::Admin);
    $this->executive = staff(Role::President);
});

/** Five worked days on Oct 1–15, with a cash bond: one payslip (basic ₱3,050, so ₱254.17 13th month). */
function payslipWithBond(object $test): void
{
    app(EnrollCashBond::class)->handle($test->guard);
    $run = payrollRun('2026-10-01', '2026-10-15');
    foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $date) {
        TimeLog::factory()->on(CarbonImmutable::parse($date))->create(['user_id' => $test->guard->id]);
    }
    app(GeneratePayslip::class)->handle($run, $test->guard, $test->accounting);
}

/** A just-cause resignation with today as the last day, accepted by HR. */
function resignedToday(object $test, bool $consent = true): Resignation
{
    $resignation = app(FileResignation::class)->handle($test->guard, ResignationReason::JustCause, null, 'I am leaving today for a just cause, as explained.', CarbonImmutable::today(), false, consentsToDeduction: $consent);

    return app(AcceptResignation::class)->handle($resignation, $test->hr, CarbonImmutable::today(), null);
}

function loanOf(object $test, float $owed): EmployeeDeduction
{
    return EmployeeDeduction::query()->forceCreate(['user_id' => $test->guard->id, 'label' => 'Rice loan', 'type' => DeductionType::Loan, 'installment_amount' => 100, 'original_amount' => $owed, 'remaining_balance' => $owed, 'is_active' => true]);
}

it('takes ₱100 a cut-off for the cash bond, plus what was paid in before, with corrections only with a reason', function () {
    payslipWithBond($this);

    $record = app(RecordCashBondEntry::class);
    $record->handle($this->guard, CashBondEntry::OPENING, 1500, 'From the old ledger', $this->accounting);
    expect(fn () => $record->handle($this->guard, CashBondEntry::OPENING, 200, null, $this->accounting))->toThrow(ValidationException::class, 'already set');
    expect(fn () => $record->handle($this->guard, CashBondEntry::ADJUSTMENT, -100, null, $this->accounting))->toThrow(ValidationException::class, 'reason');
    $record->handle($this->guard, CashBondEntry::ADJUSTMENT, -100, 'Double-counted in March', $this->accounting);

    expect(app(CashBondBalanceQuery::class)->forUser($this->guard->id))->toMatchArray([
        'per_cutoff' => 100.0, 'opening' => 1500.0, 'collected' => 100.0, 'adjustments' => -100.0, 'balance' => 1500.0,
    ]);
});

it('computes the statement: 13th month and cash bond refund, minus what\'s owed, the last salary shown but not counted', function () {
    payslipWithBond($this);
    loanOf($this, 300);
    $finalPay = app(PrepareFinalPay::class)->handle(resignedToday($this), $this->accounting);

    $lines = collect($finalPay->lines);
    expect($lines->firstWhere('label', 'Salary up to the last day')['kind'])->toBe('info')
        ->and($lines->firstWhere('label', '13th month, 2026 (pro-rated)')['amount'])->toBe(254.17)
        // Stored as JSON, so 100.0 comes back as 100: compared by value.
        ->and($lines->firstWhere('label', 'Cash bond refund')['amount'])->toEqual(100)
        ->and($lines->firstWhere('label', 'Loan: Rice loan')['kind'])->toBe('deduct');

    expect((float) $finalPay->total_pay)->toBe(354.17)
        ->and((float) $finalPay->total_deducted)->toBe(300.0)
        ->and((float) $finalPay->net)->toBe(54.17)
        ->and((float) $finalPay->receivable)->toBe(0.0);
});

it('shows but doesn\'t deduct what\'s owed without written consent', function () {
    payslipWithBond($this);
    loanOf($this, 300);
    $finalPay = app(PrepareFinalPay::class)->handle(resignedToday($this, consent: false), $this->accounting);

    expect(collect($finalPay->lines)->firstWhere('label', 'Loan: Rice loan')['kind'])->toBe('info')
        ->and((float) $finalPay->net)->toBe(354.17);
});

it('never goes below zero: the rest is a receivable', function () {
    payslipWithBond($this);
    loanOf($this, 1000);
    $finalPay = app(PrepareFinalPay::class)->handle(resignedToday($this), $this->accounting);

    expect((float) $finalPay->net)->toBe(0.0)->and((float) $finalPay->receivable)->toBe(645.83);
});

it('is signed by Accounting, then the Executive, whose signature releases it', function () {
    payslipWithBond($this);
    $loan = loanOf($this, 300);
    $resignation = resignedToday($this);
    $finalPay = app(PrepareFinalPay::class)->handle($resignation, $this->accounting);
    $finalPay = app(UpdateFinalPayAdjustments::class)->handle($finalPay, $this->accounting, 'Unused uniform allowance', 46.0, 'Refund per policy');
    expect((float) $finalPay->net)->toBe(100.17);

    app(SubmitFinalPay::class)->handle($finalPay, $this->accounting);
    Notification::assertSentTo($this->accounting, FinalPayNotice::class, fn (FinalPayNotice $notice) => $notice->event === FinalPayNotice::TO_SIGN);
    Notification::assertNotSentTo([$this->executive, $this->hr, $this->opsHq, $this->admin], FinalPayNotice::class);

    // Frozen while it's being signed; the Executive waits for Accounting; HR, Operation HQ and Admin don't sign.
    expect(fn () => app(PrepareFinalPay::class)->handle($resignation, $this->accounting))->toThrow(ValidationException::class, 'Send it back');
    expect(fn () => app(SignFinalPay::class)->handle($finalPay, $this->executive))->toThrow(ValidationException::class, 'signs last, after Accounting');
    foreach ([$this->hr, $this->opsHq, $this->admin] as $notASigner) {
        expect(fn () => app(SignFinalPay::class)->handle($finalPay, $notASigner))->toThrow(ValidationException::class, 'nothing for you to sign');
    }

    app(SignFinalPay::class)->handle($finalPay, $this->accounting);
    Notification::assertSentTo($this->executive, FinalPayNotice::class, fn (FinalPayNotice $notice) => $notice->event === FinalPayNotice::TO_SIGN);

    $released = app(SignFinalPay::class)->handle($finalPay, $this->executive);
    expect($released->status)->toBe(FinalPayStatus::Released)->and($released->released_at)->not->toBeNull();

    // The bond is refunded and stops; the loan is settled; the clearance item is ticked.
    expect(app(CashBondBalanceQuery::class)->forUser($this->guard->id))->toMatchArray(['refunded' => -100.0, 'balance' => 0.0, 'per_cutoff' => 0.0])
        ->and($loan->fresh())->remaining_balance->toBe('0.00')->is_active->toBeFalse()
        ->and($resignation->fresh()?->isCleared(ClearanceItem::FinalPay))->toBeTrue();
    Notification::assertSentTo($this->guard, FinalPayNotice::class, fn (FinalPayNotice $notice) => $notice->event === FinalPayNotice::RELEASED_TO_EMPLOYEE);

    // December's 13th month run won't pay it again.
    expect(app(ThirteenthMonthBasisQuery::class)->handle(2026, CarbonImmutable::parse('2026-12-31'))[$this->guard->id])
        ->earlier_paid->toBe(254.17)->amount->toBe(0.0);
});

it('can be sent back by a signer, clearing the signatures, and the person never signs their own', function () {
    payslipWithBond($this);
    $finalPay = app(PrepareFinalPay::class)->handle(resignedToday($this), $this->accounting);
    app(SubmitFinalPay::class)->handle($finalPay, $this->accounting);
    app(SignFinalPay::class)->handle($finalPay, $this->accounting);

    // Only a signer sends it back: HR isn't one any more.
    expect(fn () => app(ReturnFinalPay::class)->handle($finalPay, $this->hr, 'The uniform refund is missing.'))->toThrow(AuthorizationException::class);
    app(ReturnFinalPay::class)->handle($finalPay, $this->executive, 'The uniform refund is missing.');
    $finalPay->refresh();
    expect($finalPay->status)->toBe(FinalPayStatus::Draft)->and($finalPay->signoffs)->toBeNull()->and($finalPay->return_note)->toBe('The uniform refund is missing.');
    Notification::assertSentTo($this->accounting, FinalPayNotice::class, fn (FinalPayNotice $notice) => $notice->event === FinalPayNotice::RETURNED);
});

it('waits for the last day before preparing', function () {
    $resignation = app(FileResignation::class)->handle($this->guard, ResignationReason::AnotherJob, null, 'Leaving next month for another job.', CarbonImmutable::parse('2026-11-14'), false);
    $accepted = app(AcceptResignation::class)->handle($resignation, $this->hr, CarbonImmutable::parse('2026-11-14'), null);

    expect(fn () => app(PrepareFinalPay::class)->handle($accepted, $this->accounting))->toThrow(ValidationException::class, 'from the last day');
});

it('starts the cash bond when a guard registers, and opens the pages to the right people', function () {
    EmployeeProfile::factory()->create(['employee_number' => '123456-ABC-1234', 'email' => 'new.guard@example.test', 'is_office_staff' => false, 'user_id' => null]);
    $guard = app(ClaimEmployeeAccount::class)->handle('123456-ABC-1234', 'new.guard@example.test', 'a-long-password-123');
    expect(EmployeeDeduction::query()->where('user_id', $guard->id)->where('type', DeductionType::CashBond)->value('installment_amount'))->toBe('100.00');

    // Only Accounting and the Executive see final pay amounts.
    foreach ([$this->accounting, $this->executive] as $signer) {
        $this->actingAs($signer)->get(route('final-pay'))->assertOk();
    }
    foreach ([$this->guard, $this->hr, $this->opsHq, $this->admin] as $other) {
        $this->actingAs($other)->get(route('final-pay'))->assertForbidden();
    }
    $this->actingAs($this->accounting)->get(route('cash-bonds'))->assertOk();
    $this->actingAs($this->hr)->get(route('cash-bonds'))->assertForbidden();
});
