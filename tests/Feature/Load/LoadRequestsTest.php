<?php

use App\Actions\Load\ReviewLoadRequest;
use App\Actions\Load\SubmitLoadRequest;
use App\Actions\Payroll\GeneratePayslip;
use App\Enums\DeductionType;
use App\Enums\LoadRequestStatus;
use App\Enums\Role;
use App\Livewire\Admin\LoadRequests;
use App\Livewire\Load\BuyLoad;
use App\Models\ActivityLog;
use App\Models\EmployeeDeduction;
use App\Models\LoadRequest;
use App\Models\TimeLog;
use App\Notifications\LoadRequestNotice;
use App\Support\PhilippineFormats;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function requestLoad(mixed $as, array $overrides = []): Testable
{
    $component = Livewire::actingAs($as)->test(BuyLoad::class);

    foreach (['network' => 'globe', 'mobileNumber' => '+63 917 123 4567', 'amount' => 100, 'authorized' => true, ...$overrides] as $field => $value) {
        $component->set($field, $value);
    }

    return $component->call('submit');
}

function loadProof(): UploadedFile
{
    return UploadedFile::fake()->image('load-confirmation.png', 400, 700);
}

beforeEach(function () {
    Storage::fake((string) config('load.disk'));
    Notification::fake();
    $this->guard = employee();
    $this->admin = staff(Role::Admin);
});

it('normalizes Philippine mobile numbers', function (string $input, ?string $expected) {
    expect(PhilippineFormats::mobileNumber($input))->toBe($expected);
})->with([
    ['0917 123 4567', '09171234567'],
    ['+63 917-123-4567', '09171234567'],
    ['9171234567', '09171234567'],
    ['0217 123 4567', null],
    ['12345', null],
]);

it('takes a load request on credit, with no GCash, and tells the Admins', function () {
    requestLoad($this->guard)->assertHasNoErrors()->assertSee('Waiting for approval');

    $request = LoadRequest::query()->sole();
    expect($request->status)->toBe(LoadRequestStatus::Pending)
        ->and($request->mobile_number)->toBe('09171234567')
        ->and($request->amount)->toBe(100)
        ->and(EmployeeDeduction::query()->count())->toBe(0);      // nothing owed until approved

    Notification::assertSentTo($this->admin, LoadRequestNotice::class, fn (LoadRequestNotice $notice) => $notice->event === LoadRequestNotice::SUBMITTED);
    expect(ActivityLog::query()->where('event', 'load.submitted')->exists())->toBeTrue();
});

it('creates a Load deduction on approval, which the next payslip takes in full', function () {
    requestLoad($this->guard, ['amount' => 300]);
    $request = LoadRequest::query()->sole();

    Livewire::actingAs($this->admin)->test(LoadRequests::class)
        ->assertSee('₱300 Globe')
        ->call('startApprove', $request->id)
        ->call('approve')->assertHasErrors(['proof'])          // a receipt is required
        ->set('proof', loadProof())
        ->call('approve')
        ->assertHasNoErrors();

    $request->refresh();
    $deduction = $request->deduction;
    expect($request->status)->toBe(LoadRequestStatus::Approved)
        ->and($request->approved_by)->toBe($this->admin->id)
        ->and($deduction)->not->toBeNull()
        ->and($deduction->type)->toBe(DeductionType::Load)
        ->and($deduction->user_id)->toBe($this->guard->id)
        ->and($deduction->installment_amount)->toBe('300.00')
        ->and($deduction->remaining_balance)->toBe('300.00');

    // The employee's receipt: attached to the email, and viewable in the app by them (not by other employees).
    expect($request->hasProof())->toBeTrue();
    Notification::assertSentTo($this->guard, LoadRequestNotice::class, function (LoadRequestNotice $notice) {
        return $notice->event === LoadRequestNotice::APPROVED
            && count($notice->toMail($this->guard)->rawAttachments) === 1;
    });
    Livewire::actingAs($this->guard)->test(BuyLoad::class)->assertSee('₱300.00')->assertSee('To be deducted from your next payslip')->assertSee(route('load.receipt', $request));
    $this->actingAs($this->guard)->get(route('load.receipt', $request))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->actingAs(employee())->get(route('load.receipt', $request))->assertForbidden();
    $this->actingAs(staff(Role::Accounting))->get(route('load.receipt', $request))->assertOk();

    // The next payslip deducts it, and the deduction switches itself off.
    $run = payrollRun('2026-10-01', '2026-10-15');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-02'))->create(['user_id' => $this->guard->id]);
    $record = app(GeneratePayslip::class)->handle($run, $this->guard, staff(Role::Accounting));

    expect(collect($record->loan_lines)->firstWhere('label', $request->deductionLabel())['amount'])->toEqual(300)
        ->and($deduction->fresh())->remaining_balance->toBe('0.00')->is_active->toBeFalse();
});

it('rejects with a reason and charges nothing', function () {
    requestLoad($this->guard);
    $request = LoadRequest::query()->sole();

    Livewire::actingAs($this->admin)->test(LoadRequests::class)
        ->call('startReject', $request->id)
        ->call('reject')->assertHasErrors(['reason'])
        ->set('reason', 'Number not reachable')
        ->call('reject')->assertHasNoErrors();

    expect($request->fresh())->status->toBe(LoadRequestStatus::Rejected)->rejection_reason->toBe('Number not reachable')
        ->and(EmployeeDeduction::query()->count())->toBe(0);
    Notification::assertSentTo($this->guard, LoadRequestNotice::class, fn (LoadRequestNotice $notice) => $notice->event === LoadRequestNotice::REJECTED);
});

it('never charges twice, and nobody approves their own load', function () {
    requestLoad($this->guard);
    $request = LoadRequest::query()->sole();
    $review = app(ReviewLoadRequest::class);

    $review->handle($request, LoadRequestStatus::Approved, null, $this->admin, loadProof());
    expect(fn () => $review->handle($request, LoadRequestStatus::Approved, null, staff(Role::Admin), loadProof()))->toThrow(ValidationException::class, 'Not possible');
    // The refused second approval left no stray receipt behind.
    expect(Storage::disk((string) config('load.disk'))->allFiles())->toHaveCount(1);
    expect(EmployeeDeduction::query()->count())->toBe(1);

    // An Admin asking for load: another Admin approves it.
    requestLoad($this->admin);
    $own = LoadRequest::query()->where('user_id', $this->admin->id)->sole();
    expect(fn () => $review->handle($own, LoadRequestStatus::Approved, null, $this->admin, loadProof()))->toThrow(ValidationException::class, 'your own load request');
    Livewire::actingAs($this->admin)->test(LoadRequests::class)->assertSee('another Admin has to approve it');
});

it('checks the network, amount and number in the Action itself, and caps waiting requests', function () {
    $submit = app(SubmitLoadRequest::class);

    expect(fn () => $submit->handle($this->guard, ['network' => 'globe', 'mobile_number' => '0917 123 4567', 'amount' => 75, 'authorized' => true]))->toThrow(ValidationException::class)
        ->and(fn () => $submit->handle($this->guard, ['network' => 'sun', 'mobile_number' => '0917 123 4567', 'amount' => 100, 'authorized' => true]))->toThrow(ValidationException::class)
        ->and(fn () => $submit->handle($this->guard, ['network' => 'globe', 'mobile_number' => '12345', 'amount' => 100, 'authorized' => true]))->toThrow(ValidationException::class);

    foreach (range(1, (int) config('load.max_open_requests')) as $i) {
        $submit->handle($this->guard, ['network' => 'globe', 'mobile_number' => '0917 123 4567', 'amount' => 50, 'authorized' => true]);
    }
    expect(fn () => $submit->handle($this->guard, ['network' => 'globe', 'mobile_number' => '0917 123 4567', 'amount' => 50, 'authorized' => true]))->toThrow(ValidationException::class, 'waiting');
});

it('lets the employee cancel only while it is waiting', function () {
    requestLoad($this->guard);
    $request = LoadRequest::query()->sole();

    Livewire::actingAs($this->guard)->test(BuyLoad::class)->call('cancel', $request->id)->assertHasNoErrors();
    expect($request->fresh()?->status)->toBe(LoadRequestStatus::Cancelled);

    requestLoad($this->guard);
    $approved = LoadRequest::query()->where('status', LoadRequestStatus::Pending)->sole();
    app(ReviewLoadRequest::class)->handle($approved, LoadRequestStatus::Approved, null, $this->admin, loadProof());
    Livewire::actingAs($this->guard)->test(BuyLoad::class)->call('cancel', $approved->id)->assertHasErrors(['cancel']);
    expect($approved->fresh()?->status)->toBe(LoadRequestStatus::Approved);
});

it('keeps the queue to Admins, and Load out of the manual deduction types', function () {
    $this->actingAs($this->guard)->get(route('load-requests'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('load-requests'))->assertOk();
    $this->actingAs($this->guard)->get(route('load'))->assertOk()->assertSee('Borrow load');

    expect(DeductionType::manual())->not->toContain(DeductionType::Load)
        ->and(DeductionType::Load->tracksBalance())->toBeTrue();
});

it('needs the written authorization to deduct it from pay, and keeps the words', function () {
    requestLoad($this->guard, ['authorized' => false])->assertHasErrors('authorized');
    expect(fn () => app(SubmitLoadRequest::class)->handle($this->guard, ['network' => 'globe', 'mobile_number' => '0917 123 4567', 'amount' => 100]))->toThrow(ValidationException::class, 'authorize the deduction');
    expect(LoadRequest::query()->count())->toBe(0);

    requestLoad($this->guard, ['amount' => 300])->assertHasNoErrors();
    $request = LoadRequest::query()->sole();
    expect($request->deduction_authorization)->toBe('I authorize the company to deduct ₱300 from my next payslip for this load (Labor Code Art. 113).')
        ->and($request->authorized_at)->not->toBeNull();
});
