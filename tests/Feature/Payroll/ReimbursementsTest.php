<?php

use App\Actions\Payroll\CancelReimbursement;
use App\Actions\Payroll\DecideReimbursement;
use App\Actions\Payroll\FileReimbursement;
use App\Actions\Payroll\GeneratePayslip;
use App\Actions\Payroll\SaveReimbursementLimits;
use App\Enums\AdjustmentCategory;
use App\Enums\ReimbursementCategory;
use App\Enums\ReimbursementStatus;
use App\Enums\Role;
use App\Livewire\Admin\ReimbursementRequests;
use App\Livewire\Reimbursements\MyReimbursements;
use App\Models\ActivityLog;
use App\Models\Reimbursement;
use App\Models\TimeLog;
use App\Notifications\ReimbursementNotice;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Reimbursements (roadmap 25), office staff only: filed with the receipt, approved
// by the Executive, paid back on the next payslip as a non-taxable line.

beforeEach(function () {
    Notification::fake();
    Storage::fake((string) config('payroll.concerns.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00'));

    $this->clerk = staff(Role::Hr);
    $this->accounting = staff(Role::Accounting);
    $this->executive = staff(Role::President);
    $this->guard = employee();
});

function filed(object $test, float $amount = 450.0): Reimbursement
{
    return app(FileReimbursement::class)->handle($test->clerk, ReimbursementCategory::Transportation, CarbonImmutable::parse('2026-10-14'), $amount, 'Grab to SSS Pasig and back, remitting September contributions.', UploadedFile::fake()->image('receipt.jpg'));
}

it('is for office staff only, with the receipt, and tells the Executive', function () {
    expect(Reimbursement::canFile($this->clerk))->toBeTrue()
        ->and(Reimbursement::canFile($this->guard))->toBeFalse()
        ->and(Reimbursement::canFile($this->executive))->toBeFalse(); // paid outside payroll

    expect(fn () => app(FileReimbursement::class)->handle($this->guard, ReimbursementCategory::Meals, CarbonImmutable::today(), 100, 'Lunch at the post for the client.', UploadedFile::fake()->image('r.jpg')))->toThrow(AuthorizationException::class);

    $reimbursement = filed($this);
    expect($reimbursement->status)->toBe(ReimbursementStatus::Pending);
    Storage::disk((string) config('payroll.concerns.disk'))->assertExists($reimbursement->receipt_path);
    Notification::assertSentTo($this->executive, ReimbursementNotice::class, fn (ReimbursementNotice $n) => $n->event === ReimbursementNotice::FILED);
});

it('keeps to the last 60 days and up to ₱20,000, with a purpose', function () {
    $file = app(FileReimbursement::class);
    $receipt = fn () => UploadedFile::fake()->image('r.jpg');

    expect(fn () => $file->handle($this->clerk, ReimbursementCategory::Fees, CarbonImmutable::parse('2026-08-01'), 300, 'Notarial fee for the contract.', $receipt()))->toThrow(ValidationException::class, 'last 60 days');
    expect(fn () => $file->handle($this->clerk, ReimbursementCategory::Supplies, CarbonImmutable::today(), 25000, 'A new laptop for the office.', $receipt()))->toThrow(ValidationException::class, 'Bigger purchases');
    expect(fn () => $file->handle($this->clerk, ReimbursementCategory::Other, CarbonImmutable::today(), 100, 'Stuff', $receipt()))->toThrow(ValidationException::class, 'what it was for');
});

it('is approved by the Executive only, never their own, and a rejection needs a reason', function () {
    $reimbursement = filed($this);

    expect(fn () => app(DecideReimbursement::class)->handle($this->accounting, $reimbursement, true, null))->toThrow(AuthorizationException::class);
    expect(fn () => app(DecideReimbursement::class)->handle($this->executive, $reimbursement, false, ''))->toThrow(ValidationException::class, 'why');

    app(DecideReimbursement::class)->handle($this->executive, $reimbursement, true, null);
    expect($reimbursement->fresh()?->status)->toBe(ReimbursementStatus::Approved);
    Notification::assertSentTo($this->clerk, ReimbursementNotice::class, fn (ReimbursementNotice $n) => $n->event === ReimbursementNotice::DECIDED && str_contains($n->toArray($this->clerk)['body'], 'next payslip'));
    Notification::assertSentTo($this->accounting, ReimbursementNotice::class, fn (ReimbursementNotice $n) => $n->event === ReimbursementNotice::TO_PAY);
});

it('pays it back on the next payslip, not taxed, and only once', function () {
    $reimbursement = filed($this);
    app(DecideReimbursement::class)->handle($this->executive, $reimbursement, true, null);

    $run = payrollRun('2026-10-16', '2026-10-31');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-16'))->create(['user_id' => $this->clerk->id]);
    $payslip = app(GeneratePayslip::class)->handle($run, $this->clerk, $this->accounting);

    $line = collect($payslip->adjustment_lines)->sole();
    expect((float) $line['amount'])->toBe(450.0)
        ->and($line['label'])->toStartWith('Reimbursement: Transportation, Oct 14')
        ->and((float) $payslip->adjustment_earnings)->toBe(450.0)
        ->and(AdjustmentCategory::Reimbursement->isTaxable())->toBeFalse();
    expect($reimbursement->fresh())->status->toBe(ReimbursementStatus::Paid)->paid_in_run_id->toBe($run->id);
});

it('can be cancelled by the employee while it waits', function () {
    $reimbursement = filed($this);
    expect(fn () => app(CancelReimbursement::class)->handle($this->accounting, $reimbursement))->toThrow(AuthorizationException::class);

    app(CancelReimbursement::class)->handle($this->clerk, $reimbursement);
    expect($reimbursement->fresh()?->status)->toBe(ReimbursementStatus::Cancelled);
    expect(fn () => app(DecideReimbursement::class)->handle($this->executive, $reimbursement->fresh(), true, null))->toThrow(ValidationException::class, 'already decided');
});

it('works from the pages, and keeps the receipt private', function () {
    Livewire::actingAs($this->clerk)->test(MyReimbursements::class)
        ->call('start')
        ->set('acknowledges', true)
        ->set('category', 'fees')
        ->set('amount', '300')
        ->set('purpose', 'Notarial fee for the Makati CBD client contract.')
        ->set('receipt', UploadedFile::fake()->image('receipt.jpg'))
        ->call('file')
        ->assertHasNoErrors()
        ->assertSee('Waiting for approval');

    $reimbursement = Reimbursement::query()->sole();
    $item = collect(Navigation::for($this->executive))->flatMap(fn (array $section) => $section['items'])->firstWhere('route', 'reimbursement-requests');
    expect($item['badge'] ?? null)->toBe(1);

    Livewire::actingAs($this->executive)->test(ReimbursementRequests::class)
        ->call('open', $reimbursement->id)
        ->call('approve')
        ->assertHasNoErrors();
    expect($reimbursement->fresh()?->status)->toBe(ReimbursementStatus::Approved);

    $this->actingAs($this->guard)->get(route('reimbursements'))->assertForbidden();
    $this->actingAs($this->guard)->get(route('reimbursement-requests'))->assertForbidden();
    $this->actingAs($this->accounting)->get(route('reimbursement-requests'))->assertOk();
    $this->actingAs($this->clerk)->get(route('reimbursements.receipt', $reimbursement))->assertOk();
    $this->actingAs($this->guard)->get(route('reimbursements.receipt', $reimbursement))->assertForbidden();
});

it('asks for the expense certification, and keeps what they certified', function () {
    $page = Livewire::actingAs($this->clerk)->test(MyReimbursements::class)
        ->call('start')
        ->set('amount', '120')
        ->set('purpose', 'Grab to the BIR office in Makati and back.')
        ->set('receipt', UploadedFile::fake()->image('receipt.jpg'))
        ->assertSee('The receipt is genuine, and I spent this money on company business.')
        ->call('file')->assertHasErrors(['acknowledges' => 'accepted']);
    expect(Reimbursement::query()->count())->toBe(0);

    $page->set('acknowledges', true)->call('file')->assertHasNoErrors();
    $reimbursement = Reimbursement::query()->sole();
    expect($reimbursement->acknowledgement)->toBe(Reimbursement::acknowledgementLines())
        ->and($reimbursement->acknowledged_at?->toDateTimeString())->toBe('2026-10-16 10:00:00');

    Livewire::actingAs($this->executive)->test(ReimbursementRequests::class)->call('open', $reimbursement->id)->assertSee('They read and acknowledged 3 points');
});

it('lets Accounting set the limits in the app, audited, and the forms follow', function () {
    app(SaveReimbursementLimits::class)->handle($this->accounting, 30, 5000, 1000);
    expect(Reimbursement::limits())->toBe(['file_within_days' => 30, 'max_amount' => 5000, 'deployment_max_amount' => 1000])
        ->and(ActivityLog::query()->where('event', 'reimbursement.limits_changed')->count())->toBe(1);

    expect(fn () => app(FileReimbursement::class)->handle($this->clerk, ReimbursementCategory::Supplies, CarbonImmutable::today(), 6000, 'Printer for the HQ office.', UploadedFile::fake()->image('r.jpg')))
        ->toThrow(ValidationException::class, 'From ₱1 to ₱5,000');
    expect(fn () => app(FileReimbursement::class)->handle($this->clerk, ReimbursementCategory::Fees, CarbonImmutable::parse('2026-09-01'), 300, 'Notarial fee for the contract.', UploadedFile::fake()->image('r.jpg')))
        ->toThrow(ValidationException::class, 'last 30 days');
    Livewire::actingAs($this->clerk)->test(MyReimbursements::class)->call('start')->assertSee('File within 30 days of spending it. Up to ₱5,000 per request');

    // A guard's deployment claim has its own cap.
    $guard = $this->guard;
    expect(fn () => app(FileReimbursement::class)->handle($guard, ReimbursementCategory::Deployment, CarbonImmutable::today(), 1500, 'Fare to the relief post and back.', UploadedFile::fake()->image('r.jpg'), filedBy: staff(Role::OperationsHq)))
        ->toThrow(ValidationException::class, 'From ₱1 to ₱1,000');

    // From the page: Accounting changes them; the Executive only sees them.
    Livewire::actingAs($this->accounting)->test(ReimbursementRequests::class)
        ->call('editLimits')->assertSet('maxAmount', '5000')
        ->set('maxAmount', '8000')->call('saveLimits')->assertHasNoErrors()
        ->assertSee('₱8,000');
    Livewire::actingAs($this->executive)->test(ReimbursementRequests::class)->assertSee('₱8,000')->call('editLimits')->assertForbidden();
    expect(fn () => app(SaveReimbursementLimits::class)->handle($this->executive, 60, 20000, 20000))->toThrow(AuthorizationException::class);
});
