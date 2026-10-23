<?php

use App\Actions\Equipment\AcknowledgeEquipment;
use App\Actions\Equipment\DecideEquipmentIncident;
use App\Actions\Equipment\ExplainEquipmentIncident;
use App\Actions\Equipment\IssueEquipment;
use App\Actions\Equipment\RejectEquipmentRequest;
use App\Actions\Equipment\ReportEquipmentIncident;
use App\Actions\Equipment\RequestEquipment;
use App\Actions\Equipment\ReturnEquipment;
use App\Actions\Equipment\SaveEquipmentItem;
use App\Actions\Equipment\SetEquipmentItemPrices;
use App\Actions\Payroll\AnswerDeductionAuthorization;
use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Enums\EquipmentIncidentDecision;
use App\Enums\EquipmentIncidentKind;
use App\Enums\EquipmentKind;
use App\Enums\EquipmentStatus;
use App\Enums\RadioStatus;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\Equipment;
use App\Livewire\Equipment\MyEquipment;
use App\Models\Client;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeRate;
use App\Models\EquipmentIncident;
use App\Models\EquipmentIssue;
use App\Models\EquipmentItem;
use App\Models\Radio;
use App\Models\User;
use App\Notifications\UpdateNotice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Uniforms, radios and gear (the owner's rules, 2026-10-23): Operations issues to guards (a
// Satellite: its region's), Admin to office staff. Every uniform is charged, with the employee's
// authority to deduct. Company property is acknowledged, and charged only if lost or damaged
// through their fault, decided by their head after they were heard (DOLE's conditions).

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00'));

    $makati = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);
    $imus = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $this->guard = employee(['client_id' => $makati->id]);
    $this->guard->forceFill(['name' => 'Gary Guard'])->save();
    $this->caviteGuard = employee(['client_id' => $imus->id]);

    $this->opsHq = staff(Role::OperationsHq);
    $this->satellite = staff(Role::SatelliteOperations);
    $this->satellite->forceFill(['operations_region' => Region::Ncr])->save();
    $this->admin = staff(Role::Admin);
    $this->hr = staff(Role::Hr);
    $this->clerk = staff(Role::Accounting);
    $this->clerk->forceFill(['name' => 'Carla Clerk'])->save();
    $this->president = staff(Role::President);

    // Admin keeps the items and sizes; Accounting sets the prices.
    $this->polo = app(SaveEquipmentItem::class)->handle(null, 'Uniform polo', EquipmentKind::Uniform, ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'], true, $this->admin);
    app(SetEquipmentItemPrices::class)->handle($this->polo, 450.0, [], $this->clerk);
    $this->flashlight = app(SaveEquipmentItem::class)->handle(null, 'Flashlight', EquipmentKind::Gear, [], true, $this->admin);
    app(SetEquipmentItemPrices::class)->handle($this->flashlight, 800.0, [], $this->clerk);
    $this->radio = new Radio;
    $this->radio->forceFill(['brand' => 'Motorola', 'model' => 'XiR P3688', 'serial_number' => '123ABC', 'status' => RadioStatus::Spare])->save();
});

function dailyRate(User $user, float $rate): void
{
    $row = new EmployeeRate;
    $row->forceFill(['employee_profile_id' => $user->employeeProfile?->id, 'daily_rate' => $rate, 'effective_from' => '2026-01-01'])->save();
}

function issueFlashlight(object $test, ?User $to = null, ?User $by = null): EquipmentIssue
{
    return app(IssueEquipment::class)->handle($by ?? $test->opsHq, $to ?? $test->guard, $test->flashlight, null, null, 1, null);
}

it('has Operations issue to guards (a Satellite only in its region) and Admin to office staff', function (string $issuer, string $person, bool $allowed) {
    expect(EquipmentIssue::canIssueTo($this->{$issuer}, $this->{$person}))->toBe($allowed);
})->with([
    'Operation HQ → guard' => ['opsHq', 'guard', true],
    'Satellite → guard in its region' => ['satellite', 'guard', true],
    'Satellite ✗ guard in another region' => ['satellite', 'caviteGuard', false],
    'Admin ✗ guard' => ['admin', 'guard', false],
    'Admin → office staff' => ['admin', 'clerk', true],
    'Operation HQ ✗ office staff' => ['opsHq', 'clerk', false],
    'HR ✗ anyone (doesn\'t issue)' => ['hr', 'clerk', false],
    'Admin ✗ themselves' => ['admin', 'admin', false],
]);

it('issues a radio from the masterlist; the guard confirms it word for word and is accountable for it', function () {
    $issue = app(IssueEquipment::class)->handle($this->opsHq, $this->guard, null, $this->radio, null, 1, null);

    expect($issue->charged)->toBeFalse()
        ->and($issue->label)->toBe('Motorola XiR P3688 radio (SN 123ABC)')
        ->and($this->radio->fresh()?->status)->toBe(RadioStatus::InUse)
        ->and($this->radio->fresh()?->assigned_user_id)->toBe($this->guard->id);
    Notification::assertSentTo($this->guard, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Equipment issued: confirm you received it' && $n->email);

    // A radio already out can't be issued twice.
    expect(fn () => app(IssueEquipment::class)->handle($this->opsHq, $this->caviteGuard, null, $this->radio->fresh(), null, 1, null))->toThrow(ValidationException::class);

    app(AcknowledgeEquipment::class)->handle($this->guard, $issue, 'fil');
    expect($issue->fresh()?->acknowledgement)->toStartWith('Natanggap ko ang Motorola XiR P3688 radio (SN 123ABC)')
        ->and($issue->fresh()?->acknowledged_at)->not->toBeNull();

    // Nothing is deducted for company property.
    expect(EmployeeDeduction::query()->where('user_id', $this->guard->id)->exists())->toBeFalse();

    app(ReturnEquipment::class)->handle($this->satellite, $issue, null);
    expect($issue->fresh()?->status)->toBe(EquipmentStatus::Returned)
        ->and($this->radio->fresh()?->status)->toBe(RadioStatus::Spare)
        ->and($this->radio->fresh()?->assigned_user_id)->toBeNull();
});

it('charges every uniform, but takes nothing until the employee authorizes the deduction', function () {
    expect(fn () => app(IssueEquipment::class)->handle($this->opsHq, $this->guard, $this->polo, null, 'L', 2, null))
        ->toThrow(ValidationException::class, 'Per payslip');

    $issue = app(IssueEquipment::class)->handle($this->opsHq, $this->guard, $this->polo, null, 'L', 2, 300.0);
    $deduction = $issue->deduction;

    expect($issue->charged)->toBeTrue()
        ->and($issue->describe())->toBe('Uniform polo (L) × 2')
        ->and($deduction?->type)->toBe(DeductionType::Uniform)
        ->and((float) $deduction?->original_amount)->toBe(900.0)
        ->and((float) $deduction?->remaining_balance)->toBe(900.0)
        ->and($deduction?->awaiting_authorization)->toBeTrue()
        ->and(EmployeeDeduction::query()->takenFromPay()->where('user_id', $this->guard->id)->exists())->toBeFalse();

    app(AnswerDeductionAuthorization::class)->handle($this->guard, $deduction, true);
    expect(EmployeeDeduction::query()->takenFromPay()->where('user_id', $this->guard->id)->count())->toBe(1);

    // A uniform is theirs, paid for: not "returned", not reported as company property.
    expect(fn () => app(ReturnEquipment::class)->handle($this->opsHq, $issue, null))->toThrow(ValidationException::class);
});

it('has Admin keep items and sizes, and Accounting set prices (Accounting is told when one is missing)', function () {
    expect(fn () => app(SaveEquipmentItem::class)->handle(null, 'Cap', EquipmentKind::Uniform, [], true, $this->opsHq))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetEquipmentItemPrices::class)->handle($this->polo, 999.0, [], $this->admin))->toThrow(AuthorizationException::class);

    $boots = app(SaveEquipmentItem::class)->handle(null, 'Combat boots', EquipmentKind::Uniform, ['39', '40', '41', '42', 'free size'], true, $this->admin);
    expect($boots->sizeList())->toBe(['39', '40', '41', '42', 'free size'])
        ->and($boots->needsPrice())->toBeTrue()
        ->and(EquipmentItem::needingPriceCount())->toBe(1);
    Notification::assertSentTo($this->clerk, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Uniform needs a price: Combat boots');

    // Can't be issued until it has a price.
    expect(fn () => app(IssueEquipment::class)->handle($this->opsHq, $this->guard, $boots, null, '41', 1, 100.0))
        ->toThrow(ValidationException::class, 'hasn\'t set this uniform\'s price for size 41');

    // One price, and a size that costs more.
    app(SetEquipmentItemPrices::class)->handle($boots, 1500.0, ['42' => 1650.0], $this->clerk);
    expect($boots->fresh()?->priceFor('41'))->toBe(1500.0)
        ->and($boots->fresh()?->priceFor('42'))->toBe(1650.0)
        ->and($boots->fresh()?->priceLabel())->toBe('₱1,500.00 to ₱1,650.00')
        ->and(EquipmentItem::needingPriceCount())->toBe(0);

    $issue = app(IssueEquipment::class)->handle($this->opsHq, $this->guard, $boots->fresh(), null, '42', 1, 550.0);
    expect((float) $issue->unit_price)->toBe(1650.0)->and((float) $issue->deduction?->original_amount)->toBe(1650.0);

    // A later price change doesn't touch what was issued; a size Admin keeps keeps its price.
    app(SetEquipmentItemPrices::class)->handle($boots->fresh(), 1800.0, ['42' => 2000.0], $this->clerk);
    app(SaveEquipmentItem::class)->handle($boots->fresh(), 'Combat boots', EquipmentKind::Uniform, ['42', '43'], true, $this->admin);
    expect((float) $issue->fresh()?->unit_price)->toBe(1650.0)
        ->and($boots->fresh()?->priceFor('42'))->toBe(2000.0)
        ->and($boots->fresh()?->priceFor('43'))->toBe(1800.0)
        ->and(fn () => app(IssueEquipment::class)->handle($this->opsHq, $this->guard, $boots->fresh(), null, '39', 1, 100.0))->toThrow(ValidationException::class, 'Choose the size');
});

it('lets Admin tick standard sizes and add custom ones, ranges included', function () {
    Livewire::actingAs($this->admin)->test(Equipment::class)
        ->call('startItem')->set('itemName', 'Duty trousers')->set('itemKind', 'uniform')
        ->call('toggleSize', 'M')->call('toggleSize', 'L')->call('toggleSize', 'M')
        ->set('customSize', '28-32, W34/L32')->call('addCustomSize')->assertHasNoErrors()
        ->assertSet('itemSizes', ['L', '28', '29', '30', '31', '32', 'W34/L32'])
        ->set('customSize', 'not a valid size!')->call('addCustomSize')->assertHasErrors('customSize')
        ->call('removeSize', '29')
        ->call('saveItem')->assertHasNoErrors();

    expect(EquipmentItem::query()->where('name', 'Duty trousers')->sole()->sizeList())->toBe(['L', '28', '30', '31', '32', 'W34/L32']);
});

it('shows Accounting only the item list, where they set prices per size', function () {
    $this->actingAs($this->clerk)->get(route('equipment'))->assertOk()->assertSee('Items and prices')->assertSee('Set price')->assertDontSee('Issue equipment');

    Livewire::actingAs($this->clerk)->test(Equipment::class)
        ->call('startPricing', $this->polo->id)->set('basePrice', '480')->set('sizePrices.6', '520')->call('savePrices')->assertHasNoErrors();
    expect($this->polo->fresh()?->priceFor('M'))->toBe(480.0)->and($this->polo->fresh()?->priceFor('3XL'))->toBe(520.0);

    Livewire::actingAs($this->admin)->test(Equipment::class)->assertSee('Items and prices')->assertDontSee('Set price')
        ->call('startPricing', $this->polo->id)->assertForbidden();
});

it('sends a guard\'s request to Operations and an office staffer\'s to Admin, then issues or turns it down', function () {
    $guardAsks = app(RequestEquipment::class)->handle($this->guard, $this->polo, 'M', 1, 'My polo is torn');
    Notification::assertSentTo([$this->opsHq, $this->satellite], UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Equipment request: Gary Guard');
    Notification::assertNotSentTo($this->admin, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Equipment request: Gary Guard');

    $clerkAsks = app(RequestEquipment::class)->handle($this->clerk, $this->flashlight, null, 1, null);
    Notification::assertSentTo($this->admin, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Equipment request: Carla Clerk');

    // Issued from the request: the same record, now with them.
    app(IssueEquipment::class)->handle($this->satellite, $this->guard, $this->polo, null, 'M', 1, 450.0, $guardAsks);
    expect($guardAsks->fresh()?->status)->toBe(EquipmentStatus::Issued)->and(EquipmentIssue::query()->count())->toBe(2);

    app(RejectEquipmentRequest::class)->handle($this->admin, $clerkAsks, 'One per office; borrow the shared one');
    expect($clerkAsks->fresh()?->status)->toBe(EquipmentStatus::Rejected);
    Notification::assertSentTo($this->clerk, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Equipment request not approved');
});

it('charges damage only after the employee was heard, by their head, within DOLE\'s limits', function () {
    dailyRate($this->guard, 700.0);
    $issue = issueFlashlight($this);

    $incident = app(ReportEquipmentIncident::class)->handle($this->opsHq, $issue, EquipmentIncidentKind::Damaged, 'Lens cracked; found on the guard house floor at 0600.');
    Notification::assertSentTo($this->guard, UpdateNotice::class, fn (UpdateNotice $n) => str_starts_with($n->title, 'Reported damaged') && $n->email && str_contains($n->body, 'Oct 28, 2026'));

    // Not before they're heard.
    expect(fn () => app(DecideEquipmentIncident::class)->handle($this->opsHq, $incident, true, 'Dropped it while on duty.', 300.0, 'Supplier quote', 100.0))
        ->toThrow(ValidationException::class, 'They can still explain');

    app(ExplainEquipmentIncident::class)->handle($this->guard, $incident, 'It fell from the shelf during the earthquake drill.');
    $incident->refresh();

    // HR isn't a guard's head; Operations is.
    expect(fn () => app(DecideEquipmentIncident::class)->handle($this->hr, $incident, false, 'Not their fault at all.'))->toThrow(AuthorizationException::class);

    // Never more than 20% of a week's wages per payslip: 700 × 6 × 20% = 840.
    expect(fn () => app(DecideEquipmentIncident::class)->handle($this->opsHq, $incident, true, 'Left it on an unsafe shelf.', 1000.0, 'Replacement price', 900.0))
        ->toThrow(ValidationException::class, 'At most ₱840.00');

    app(DecideEquipmentIncident::class)->handle($this->opsHq, $incident, true, 'Left it on an unsafe shelf.', 300.0, 'Repair quote, Oct 23', 150.0);
    $incident->refresh();
    $deduction = EmployeeDeduction::query()->findOrFail($incident->employee_deduction_id);

    expect($incident->decision)->toBe(EquipmentIncidentDecision::Charged)
        ->and($deduction->authorization_basis)->toBe(DeductionAuthorizationBasis::LossOrDamage)
        ->and($deduction->awaiting_authorization)->toBeFalse()
        ->and((float) $deduction->remaining_balance)->toBe(300.0)
        ->and((float) $deduction->installment_amount)->toBe(150.0)
        ->and($issue->fresh()?->status)->toBe(EquipmentStatus::Issued); // damaged, still with them
    Notification::assertSentTo($this->guard, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Charged: Flashlight' && $n->email);
});

it('lets the head decide once the days to explain have passed, and a lost item leaves their hands', function () {
    $issue = app(IssueEquipment::class)->handle($this->opsHq, $this->guard, null, $this->radio, null, 1, null);
    $incident = app(ReportEquipmentIncident::class)->handle($this->opsHq, $issue, EquipmentIncidentKind::Lost, 'Not turned over at the 1800 changeover; guard says it was left at the post.');
    expect($this->radio->fresh()?->status)->toBe(RadioStatus::Lost);

    $this->travelTo(CarbonImmutable::parse('2026-10-29 09:00'));
    expect($incident->fresh()?->canStillExplain())->toBeFalse()->and($incident->fresh()?->readyToDecide())->toBeTrue();

    app(DecideEquipmentIncident::class)->handle($this->satellite, $incident->fresh(), false, 'The post log shows it was handed over; the reliever lost it.');
    expect($issue->fresh()?->status)->toBe(EquipmentStatus::Lost)
        ->and(EmployeeDeduction::query()->where('user_id', $this->guard->id)->exists())->toBeFalse();
});

it('has an office staffer\'s head decide their report: the President or VP for Accounting, not Admin', function () {
    dailyRate($this->clerk, 900.0);
    $issue = issueFlashlight($this, $this->clerk, $this->admin);
    $incident = app(ReportEquipmentIncident::class)->handle($this->admin, $issue, EquipmentIncidentKind::Damaged, 'Returned with a broken switch after the audit trip.');
    app(ExplainEquipmentIncident::class)->handle($this->clerk, $incident, 'The switch was already loose when I got it.');

    expect(EquipmentIncident::canDecide($this->admin, $this->clerk))->toBeFalse()
        ->and(EquipmentIncident::canDecide($this->president, $this->clerk))->toBeTrue();

    // The President sees it on the desk (Damage & loss only) and decides there.
    Livewire::actingAs($this->president)->test(Equipment::class)
        ->assertSee('Carla Clerk')->assertDontSee('Issue equipment')
        ->call('startDecide', $incident->id)->set('decisionCharge', 'no')->set('decisionReason', 'Loose before; normal wear.')->call('decide')->assertHasNoErrors();
    expect($incident->fresh()?->decision)->toBe(EquipmentIncidentDecision::NotCharged);
});

it('works from the pages: Operations issues, the guard confirms and requests on My Equipment', function () {
    Livewire::actingAs($this->opsHq)->test(Equipment::class)
        ->call('startIssue')->set('issueToId', (string) $this->guard->id)->set('issueKind', 'radio')->set('issueRadioId', (string) $this->radio->id)
        ->call('issue')->assertHasNoErrors();
    $issue = EquipmentIssue::query()->sole();

    $mine = Livewire::actingAs($this->guard)->test(MyEquipment::class)->assertSee('Motorola XiR P3688 radio (SN 123ABC)')->assertSee('I received it');
    expect(MyEquipment::waitingFor($this->guard))->toBe(1);
    $mine->call('acknowledge', $issue->id)->assertHasNoErrors()->assertSee('You confirmed receiving it');
    expect(MyEquipment::waitingFor($this->guard))->toBe(0);

    $mine->call('startRequest')->set('requestItem', (string) $this->polo->id)->set('requestSize', 'XL')->set('requestQuantity', '1')->call('request')->assertHasNoErrors();
    expect(EquipmentIssue::query()->where('status', EquipmentStatus::Requested)->count())->toBe(1);

    Livewire::actingAs($this->opsHq)->test(Equipment::class)->assertSee('Gary Guard')->assertSet('show', '');
});

it('opens the desk only for issuers, heads and Accounting (prices), and the item list only for Admin and Accounting', function () {
    $this->actingAs($this->guard)->get(route('equipment'))->assertForbidden();
    $this->actingAs(staff(Role::Developer))->get(route('equipment'))->assertForbidden();
    $this->actingAs($this->opsHq)->get(route('equipment'))->assertOk()->assertDontSee('Items and prices');
    $this->actingAs($this->admin)->get(route('equipment'))->assertOk()->assertSee('Items and prices');
    $this->actingAs($this->hr)->get(route('equipment'))->assertOk();
    $this->actingAs($this->guard)->get(route('my-equipment'))->assertOk();
});
