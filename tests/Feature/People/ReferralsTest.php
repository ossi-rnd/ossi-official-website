<?php

use App\Actions\Payroll\GeneratePayslip;
use App\Actions\People\DecideReferral;
use App\Actions\People\SaveReferralTerms;
use App\Actions\People\SubmitReferral;
use App\Enums\AdjustmentCategory;
use App\Enums\EmploymentStatus;
use App\Enums\Position;
use App\Enums\ReferralStatus;
use App\Enums\Role;
use App\Livewire\Admin\ReferralCases;
use App\Livewire\Referrals\MyReferrals;
use App\Models\Referral;
use App\Models\TimeLog;
use App\Notifications\ReferralNotice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Referrals (hiring perks): an employee refers someone; HR marks them hired; the bonus
// is paid on the referrer's payslip once the hire has stayed the days HR set.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00'));
    $this->hr = staff(Role::Hr);
    $this->accounting = staff(Role::Accounting);
    $this->referrer = employee();
    app(SaveReferralTerms::class)->handle($this->hr, 1500, 90);
});

function refer(object $test, string $phone = '+63 917-123-4567'): Referral
{
    return app(SubmitReferral::class)->handle($test->referrer, 'Pedro Santos', $phone, Position::SecurityGuard, 'Licensed guard');
}

it('takes a referral from an employee, once per person, and tells HR', function () {
    $referral = refer($this);
    expect($referral->applicant_phone)->toBe('0917 123 4567')->and($referral->status)->toBe(ReferralStatus::Submitted);
    Notification::assertSentTo($this->hr, ReferralNotice::class, fn (ReferralNotice $notice) => $notice->event === ReferralNotice::SUBMITTED);

    expect(fn () => refer($this, '09171234567'))->toThrow(ValidationException::class, 'already referred');
    expect(fn () => app(SubmitReferral::class)->handle(staff(Role::President), 'Ana Cruz', '09181234567', null, null))->toThrow(AuthorizationException::class);
});

it('freezes the bonus and its due date when HR marks the hire', function () {
    $referral = refer($this);
    $hire = employee(['hire_date' => '2026-07-01', 'name' => 'Pedro Santos']);

    expect(fn () => app(DecideReferral::class)->handle($referral, $hire->employeeProfile, null, $this->accounting))->toThrow(AuthorizationException::class);
    app(DecideReferral::class)->handle($referral, $hire->employeeProfile, null, $this->hr);

    // Changing the terms later doesn't touch a referral already hired.
    app(SaveReferralTerms::class)->handle($this->hr, 500, 30);
    expect($referral->fresh())->status->toBe(ReferralStatus::Hired)->bonus_amount->toBe('1500.00')
        ->and($referral->fresh()?->bonus_due_on?->toDateString())->toBe('2026-09-29');
    Notification::assertSentTo($this->referrer, ReferralNotice::class, fn (ReferralNotice $notice) => $notice->event === ReferralNotice::HIRED);
});

it('pays the bonus on the referrer\'s payslip once the hire stayed, and not before', function () {
    $referral = refer($this);
    $hire = employee(['hire_date' => '2026-07-01']);
    app(DecideReferral::class)->handle($referral, $hire->employeeProfile, null, $this->hr);

    // September 16-30: due Sep 29, so it's paid here.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00'));
    $run = payrollRun('2026-09-16', '2026-09-30');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-09-16'))->create(['user_id' => $this->referrer->id]);
    $payslip = app(GeneratePayslip::class)->handle($run, $this->referrer, $this->accounting);

    $line = collect($payslip->adjustment_lines)->sole();
    expect((float) $line['amount'])->toBe(1500.0)
        ->and($line['label'])->toStartWith('Referral bonus: Referred Pedro Santos')
        ->and(AdjustmentCategory::ReferralBonus->isTaxable())->toBeFalse();
    expect($referral->fresh())->status->toBe(ReferralStatus::Paid)->paid_in_run_id->toBe($run->id);
});

it('pays nothing if the hire left before the bonus was due', function () {
    $referral = refer($this);
    $hire = employee(['hire_date' => '2026-07-01']);
    app(DecideReferral::class)->handle($referral, $hire->employeeProfile, null, $this->hr);
    $hire->employeeProfile?->forceFill(['employment_status' => EmploymentStatus::Terminated])->save();

    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00'));
    $run = payrollRun('2026-09-16', '2026-09-30');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-09-16'))->create(['user_id' => $this->referrer->id]);
    $payslip = app(GeneratePayslip::class)->handle($run, $this->referrer, $this->accounting);

    expect($payslip->adjustment_lines)->toBe([])
        ->and($referral->fresh()?->status)->toBe(ReferralStatus::Forfeited);
    Notification::assertSentTo($this->referrer, ReferralNotice::class, fn (ReferralNotice $notice) => $notice->event === ReferralNotice::FORFEITED);
});

it('has a page to refer someone and one for HR', function () {
    Livewire::actingAs($this->referrer)->test(MyReferrals::class)
        ->call('start')->set('applicantName', 'Ana Cruz')->set('applicantPhone', '0918 765 4321')->call('submit')
        ->assertHasNoErrors()->assertSee('Ana Cruz')->assertSee('₱1,500');

    Livewire::actingAs($this->hr)->test(ReferralCases::class)->assertSee('Ana Cruz')
        ->call('decide', Referral::query()->sole()->id, 'not_hired')->set('decisionNote', 'Did not pass the exam.')->call('saveDecision')->assertHasNoErrors();
    expect(Referral::query()->sole()->status)->toBe(ReferralStatus::NotHired);

    $this->actingAs($this->accounting)->get(route('referral-cases'))->assertForbidden();
});
