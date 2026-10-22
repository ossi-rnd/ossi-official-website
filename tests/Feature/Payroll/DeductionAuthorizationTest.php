<?php

use App\Actions\Payroll\AnswerDeductionAuthorization;
use App\Actions\Payroll\GeneratePayslip;
use App\Actions\Payroll\SaveEmployeeDeduction;
use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Enums\Role;
use App\Livewire\Admin\Payroll;
use App\Livewire\Payroll\DeductionAuthorizations;
use App\Models\TimeLog;
use App\Notifications\DeductionAuthorizationNotice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Labor Code Art. 113: a loan, cash advance or other deduction Accounting adds needs
// the employee's written authorization (in the app, or a signed paper), or the law
// (SSS and Pag-IBIG salary loans). Asked in the app, nothing is taken until they agree.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));
    $this->accounting = staff(Role::Accounting);
    $this->guard = employee();
});

function addLoan(object $test, DeductionAuthorizationBasis $basis, DeductionType $type = DeductionType::CashAdvance)
{
    return app(SaveEmployeeDeduction::class)->handle($test->guard, 'Emergency cash advance', $type, 500, 2000, $test->accounting, $basis);
}

function loanTakenOnPayslip(object $test): string
{
    $run = payrollRun('2026-10-16', '2026-10-31');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-16'))->create(['user_id' => $test->guard->id]);

    return app(GeneratePayslip::class)->handle($run, $test->guard, $test->accounting)->loan_deductions;
}

it('asks the employee first, takes nothing until they authorize, then keeps their words', function () {
    $loan = addLoan($this, DeductionAuthorizationBasis::Employee);

    expect($loan->awaiting_authorization)->toBeTrue()->and($loan->authorized_at)->toBeNull();
    Notification::assertSentTo($this->guard, DeductionAuthorizationNotice::class, fn (DeductionAuthorizationNotice $n) => $n->event === DeductionAuthorizationNotice::REQUESTED);

    Livewire::actingAs($this->guard)->test(DeductionAuthorizations::class)
        ->assertSee('Please review')
        ->assertSee('I authorize the company to deduct ₱500.00 from each payslip for my cash advance "Emergency cash advance" until ₱2,000.00 is paid (Labor Code Art. 113).')
        ->call('authorizeDeduction', $loan->id)
        ->assertDontSee('Please review');

    $loan->refresh();
    expect($loan->awaiting_authorization)->toBeFalse()
        ->and($loan->authorization)->toStartWith('I authorize the company to deduct ₱500.00')
        ->and($loan->authorized_at?->toDateTimeString())->toBe('2026-10-17 10:00:00');
    Notification::assertSentTo($this->accounting, DeductionAuthorizationNotice::class, fn (DeductionAuthorizationNotice $n) => $n->event === DeductionAuthorizationNotice::AUTHORIZED);

    expect(loanTakenOnPayslip($this))->not->toBe('0.00');
});

it('takes nothing while waiting, nor once they decline, and tells Accounting', function () {
    $loan = addLoan($this, DeductionAuthorizationBasis::Employee);
    expect(loanTakenOnPayslip($this))->toBe('0.00');

    app(AnswerDeductionAuthorization::class)->handle($this->guard, $loan, false);
    expect($loan->fresh()?->declined_at)->not->toBeNull();
    Notification::assertSentTo($this->accounting, DeductionAuthorizationNotice::class, fn (DeductionAuthorizationNotice $n) => $n->event === DeductionAuthorizationNotice::DECLINED);

    // Answered once; never someone else's.
    expect(fn () => app(AnswerDeductionAuthorization::class)->handle($this->guard, $loan->fresh(), true))->toThrow(ValidationException::class, 'already answered')
        ->and(fn () => app(AnswerDeductionAuthorization::class)->handle(employee(), addLoan($this, DeductionAuthorizationBasis::Employee), true))->toThrow(AuthorizationException::class);
});

it('applies at once when signed on paper or a government loan, and says which', function () {
    $paper = addLoan($this, DeductionAuthorizationBasis::Paper);
    $law = addLoan($this, DeductionAuthorizationBasis::Law, DeductionType::Loan);

    expect($paper->awaiting_authorization)->toBeFalse()
        ->and($paper->authorization)->toBe("Signed on paper; recorded by {$this->accounting->name}.")
        ->and($law->authorization)->toContain('SSS or Pag-IBIG')
        ->and($law->authorized_at)->not->toBeNull();
    Notification::assertNotSentTo($this->guard, DeductionAuthorizationNotice::class);

    // The cash bond is enrolled with the employment: no authorization asked.
    $bond = app(SaveEmployeeDeduction::class)->handle($this->guard, 'Cash bond', DeductionType::CashBond, 100, null, $this->accounting, DeductionAuthorizationBasis::Employee);
    expect($bond->awaiting_authorization)->toBeFalse()->and($bond->authorization_basis)->toBeNull();
});

it('lets Accounting choose the basis on the payroll page, asking the employee by default', function () {
    $run = payrollRun('2026-10-16', '2026-10-31');

    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $run->id)->call('viewPayslip', $this->guard->id)
        ->assertSet('deductionBasis', 'employee')
        ->set('deductionLabel', 'Uniform replacement')->set('deductionType', 'uniform')->set('deductionInstallment', '150')
        ->call('addDeduction')->assertHasNoErrors()
        ->assertSee('Waiting for their authorization');
});
