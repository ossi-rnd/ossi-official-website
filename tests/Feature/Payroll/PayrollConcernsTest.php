<?php

use App\Actions\Payroll\AnswerPayrollConcern;
use App\Actions\Payroll\FilePayrollConcern;
use App\Actions\Payroll\GeneratePayslip;
use App\Enums\AdjustmentCategory;
use App\Enums\PayrollConcernCategory;
use App\Enums\PayrollConcernStatus;
use App\Enums\Role;
use App\Livewire\Admin\PayrollConcerns;
use App\Livewire\Payroll\PayslipConcerns;
use App\Models\PayrollConcern;
use App\Models\TimeLog;
use App\Notifications\PayrollConcernNotice;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// "Report a problem" on a payslip (roadmap 14): the employee reports, Accounting
// is told at once, and answers by paying it on an open payslip, explaining, or
// declining. The employee is told either way.

beforeEach(function () {
    Notification::fake();
    Storage::fake((string) config('payroll.concerns.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

    $this->accounting = staff(Role::Accounting);
    $this->guard = employee();

    // Oct 1–15 paid and approved; Oct 16–31 processing, worked.
    $this->paid = payrollRun('2026-10-01', '2026-10-15');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-05'))->create(['user_id' => $this->guard->id]);
    app(GeneratePayslip::class)->handle($this->paid, $this->guard, $this->accounting);
    $this->paid->forceFill(['status' => 'approved'])->save();

    $this->open = payrollRun('2026-10-16', '2026-10-31');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-16'))->create(['user_id' => $this->guard->id]);
});

function reported(object $test, ?UploadedFile $file = null): PayrollConcern
{
    return app(FilePayrollConcern::class)->handle($test->guard, $test->paid, PayrollConcernCategory::MissingDays, 'I worked Oct 10 at Makati CBD; my phone was broken.', $file);
}

it('tells Accounting at once, keeps the photo private, and doesn\'t file the same thing twice', function () {
    $concern = reported($this, UploadedFile::fake()->image('dtr.jpg'));

    expect($concern->status)->toBe(PayrollConcernStatus::Open)->and($concern->attachment_mime)->toBe('image/jpeg');
    Storage::disk((string) config('payroll.concerns.disk'))->assertExists((string) $concern->attachment_path);
    Notification::assertSentTo($this->accounting, PayrollConcernNotice::class, fn (PayrollConcernNotice $notice) => $notice->event === PayrollConcernNotice::FILED
        && str_contains($notice->toArray($this->accounting)['url'], 'payroll-concerns'));

    expect(fn () => reported($this))->toThrow(ValidationException::class, 'already reported');

    // The photo: the employee and Accounting only.
    $this->actingAs($this->guard)->get(route('payroll-concerns.attachment', $concern))->assertOk();
    $this->actingAs($this->accounting)->get(route('payroll-concerns.attachment', $concern))->assertOk();
    $this->actingAs(employee())->get(route('payroll-concerns.attachment', $concern))->assertForbidden();
    $this->actingAs(staff(Role::Hr))->get(route('payroll-concerns.attachment', $concern))->assertForbidden();
});

it('asks for the details, and only on the employee\'s own visible payslips', function () {
    expect(fn () => app(FilePayrollConcern::class)->handle($this->guard, $this->paid, PayrollConcernCategory::Other, 'wrong'))->toThrow(ValidationException::class, 'Say what');

    $draft = payrollRun('2026-11-01', '2026-11-15', 'draft');
    expect(fn () => app(FilePayrollConcern::class)->handle($this->guard, $draft, PayrollConcernCategory::Other, 'Something is wrong on this payslip.'))->toThrow(ValidationException::class, 'own payslips');
});

it('pays it on a payslip still being processed, as a taxable correction, and tells the employee', function () {
    $concern = reported($this);
    expect(AnswerPayrollConcern::payableRuns($this->guard)->pluck('id')->all())->toBe([$this->open->id]);

    $answered = app(AnswerPayrollConcern::class)->handle($concern, $this->accounting, AnswerPayrollConcern::PAY, 'Confirmed with Sgt. Reyes: Oct 10 added.', $this->open, 610.0);

    expect($answered->status)->toBe(PayrollConcernStatus::Resolved)
        ->and($answered->adjustment)->category->toBe(AdjustmentCategory::Correction)->payroll_run_id->toBe($this->open->id)
        ->and((float) $answered->adjustment?->amount)->toBe(610.0);
    Notification::assertSentTo($this->guard, PayrollConcernNotice::class, fn (PayrollConcernNotice $notice) => $notice->event === PayrollConcernNotice::ANSWERED
        && str_contains($notice->toArray($this->guard)['body'], 'Added ₱610.00'));

    // On the payslip when it's generated.
    expect((float) app(GeneratePayslip::class)->handle($this->open, $this->guard, $this->accounting)->adjustment_earnings)->toBe(610.0);
});

it('can be explained or declined, always with a reply, once', function () {
    $concern = reported($this);

    expect(fn () => app(AnswerPayrollConcern::class)->handle($concern, $this->accounting, AnswerPayrollConcern::DECLINE, ''))->toThrow(ValidationException::class, 'reply');
    expect(fn () => app(AnswerPayrollConcern::class)->handle($concern, $this->accounting, AnswerPayrollConcern::PAY, 'Added.'))->toThrow(ValidationException::class, 'payslip it goes on');

    $declined = app(AnswerPayrollConcern::class)->handle($concern, $this->accounting, AnswerPayrollConcern::DECLINE, 'No roster or attendance record for Oct 10.');
    expect($declined->status)->toBe(PayrollConcernStatus::Declined)->and($declined->payroll_adjustment_id)->toBeNull();

    expect(fn () => app(AnswerPayrollConcern::class)->handle($concern, $this->accounting, AnswerPayrollConcern::EXPLAIN, 'It was right.'))->toThrow(ValidationException::class, 'already answered');
});

it('works from the pages: the employee reports under the payslip, Accounting answers from Payroll Concerns', function () {
    Livewire::actingAs($this->guard)->test(PayslipConcerns::class, ['runId' => $this->paid->id])
        ->assertSee('Report a problem')
        ->set('reporting', true)
        ->set('category', PayrollConcernCategory::Overtime->value)
        ->set('details', 'I stayed until 9 PM on Oct 8, approved by Sgt. Reyes.')
        ->call('report')
        ->assertHasNoErrors()
        ->assertSee('Waiting for Accounting');

    $concern = PayrollConcern::query()->sole();
    $labels = collect(Navigation::for($this->accounting))->flatMap(fn (array $section) => $section['items'])->firstWhere('route', 'payroll-concerns');
    expect($labels['badge'] ?? null)->toBe(1);

    Livewire::actingAs($this->accounting)->test(PayrollConcerns::class)
        ->assertSee($this->guard->name)
        ->call('open', $concern->id)
        ->assertSet('outcome', AnswerPayrollConcern::PAY)
        ->set('amount', '214')
        ->set('response', 'Overtime on Oct 8 confirmed: added.')
        ->call('answer')
        ->assertHasNoErrors();

    expect($concern->fresh()?->status)->toBe(PayrollConcernStatus::Resolved);

    $this->actingAs(staff(Role::Hr))->get(route('payroll-concerns'))->assertForbidden();
    $this->actingAs($this->guard)->get(route('payroll-concerns'))->assertForbidden();
});
