<?php

use App\Actions\Resignations\AcceptResignation;
use App\Actions\Resignations\ApplySeparations;
use App\Actions\Resignations\FileResignation;
use App\Actions\Resignations\UpdateClearance;
use App\Actions\Resignations\WithdrawResignation;
use App\Enums\ClearanceItem;
use App\Enums\DeductionType;
use App\Enums\EmploymentStatus;
use App\Enums\Region;
use App\Enums\ResignationReason;
use App\Enums\ResignationStatus;
use App\Enums\Role;
use App\Livewire\Admin\Complaints;
use App\Livewire\Admin\Resignations;
use App\Livewire\Resignations\MyResignation;
use App\Models\Client;
use App\Models\EmployeeDeduction;
use App\Models\Resignation;
use App\Notifications\ComplaintNotice;
use App\Notifications\ResignationNotice;
use App\Queries\People\ResignationReasonsQuery;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Resignation (Labor Code Art. 300): 30 days' written notice or a just cause,
// HR accepts the last day, clearance, and the account closes the day after.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
    Notification::fake();

    $client = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $this->guard = employee(['client_id' => $client->id, 'detachment' => $client->name]);
    $this->hr = staff(Role::Hr);
    $this->executive = staff(Role::President);
    $this->opsHq = staff(Role::OperationsHq);
});

function resign(object $test, $employee, string $lastDay, ResignationReason $reason = ResignationReason::AnotherJob, bool $waiver = false): Resignation
{
    return app(FileResignation::class)->handle($employee, $reason, null, 'I am resigning to take a job closer to home.', CarbonImmutable::parse($lastDay), $waiver);
}

it('files a resignation with 30 days\' notice, tells HR, and shows it in the employee\'s menu', function () {
    Livewire::actingAs($this->guard)->test(MyResignation::class)
        ->assertSet('lastDay', '2026-11-14') // 30 days ahead by default
        ->set('acknowledges', true)
        ->set('reasons', ['another_job'])
        ->set('letter', 'I am resigning to take a job closer to home.')
        ->call('submit')->assertHasNoErrors()
        ->assertSee('Waiting for HR')->assertSee('Withdraw');

    $resignation = Resignation::query()->sole();
    expect($resignation->status)->toBe(ResignationStatus::Pending)
        ->and($resignation->for_executive)->toBeFalse()
        ->and($resignation->finalPayDue()->toDateString())->toBe('2026-12-14');

    Notification::assertSentTo($this->hr, ResignationNotice::class, fn (ResignationNotice $notice) => $notice->event === ResignationNotice::FILED);
    expect(collect(Navigation::for($this->guard))->pluck('items')->flatten(1)->pluck('route'))->toContain('resignation')
        ->and(collect(Navigation::for($this->hr))->pluck('items')->flatten(1)->firstWhere('route', 'resignations')['badge'])->toBe(1);
});

it('asks for a waiver under 30 days\' notice, but not for a just cause', function () {
    expect(fn () => resign($this, $this->guard, '2026-10-25'))->toThrow(ValidationException::class, 'at least 30 days');

    $waived = resign($this, $this->guard, '2026-10-25', waiver: true);
    expect($waived->asks_waiver)->toBeTrue();

    // Only one at a time.
    expect(fn () => resign($this, $this->guard, '2026-12-01'))->toThrow(ValidationException::class, 'already a resignation');

    $other = employee();
    $justCause = resign($this, $other, '2026-10-15', ResignationReason::SeriousInsult);
    expect($justCause->asks_waiver)->toBeFalse();
});

it('lets the employee withdraw while pending, not once accepted', function () {
    $resignation = resign($this, $this->guard, '2026-11-20');

    app(WithdrawResignation::class)->handle($resignation, $this->guard);
    expect($resignation->fresh()?->status)->toBe(ResignationStatus::Withdrawn);
    Notification::assertSentTo($this->hr, ResignationNotice::class, fn (ResignationNotice $notice) => $notice->event === ResignationNotice::WITHDRAWN);

    $again = resign($this, $this->guard, '2026-11-20');
    app(AcceptResignation::class)->handle($again, $this->hr, CarbonImmutable::parse('2026-11-20'), null);
    expect(fn () => app(WithdrawResignation::class)->handle($again, $this->guard))->toThrow(ValidationException::class, 'accepted already');
});

it('accepts a last day, waiving the notice when it\'s shorter, and tells the employee and Operations', function () {
    $resignation = resign($this, $this->guard, '2026-10-30', waiver: true);

    Livewire::actingAs($this->hr)->test(Resignations::class)
        ->call('open', $resignation->id)
        ->assertSee('I am resigning')
        ->set('lastDay', '2026-10-31')->set('note', 'Please return the radio to the detachment.')
        ->call('accept')->assertHasNoErrors();

    $resignation->refresh();
    expect($resignation->status)->toBe(ResignationStatus::Accepted)
        ->and($resignation->last_day?->toDateString())->toBe('2026-10-31')
        ->and($resignation->notice_waived)->toBeTrue();

    Notification::assertSentTo($this->guard, ResignationNotice::class, fn (ResignationNotice $notice) => $notice->event === ResignationNotice::ACCEPTED);
    Notification::assertSentTo($this->opsHq, ResignationNotice::class, fn (ResignationNotice $notice) => $notice->event === ResignationNotice::ACCEPTED_OPERATIONS);
});

it('sends an HR member\'s resignation to the Executive, never to HR', function () {
    $otherHr = staff(Role::Hr);
    $resignation = resign($this, $this->hr, '2026-11-20');

    expect($resignation->for_executive)->toBeTrue();
    Notification::assertSentTo($this->executive, ResignationNotice::class);
    Notification::assertNotSentTo($otherHr, ResignationNotice::class);

    Livewire::actingAs($otherHr)->test(Resignations::class)->call('open', $resignation->id)->assertNotFound();
    expect(fn () => app(AcceptResignation::class)->handle($resignation, $otherHr, CarbonImmutable::parse('2026-11-20'), null))->toThrow(AuthorizationException::class);

    app(AcceptResignation::class)->handle($resignation, $this->executive, CarbonImmutable::parse('2026-11-20'), null);
    expect($resignation->fresh()?->status)->toBe(ResignationStatus::Accepted);
});

it('runs the clearance, closes the account the day after the last day, then completes', function () {
    // A ₱1,500 loan still to be paid.
    EmployeeDeduction::query()->forceCreate(['user_id' => $this->guard->id, 'label' => 'Cash advance', 'type' => DeductionType::Loan, 'installment_amount' => 500, 'original_amount' => 2000, 'remaining_balance' => 1500, 'is_active' => true]);
    $resignation = resign($this, $this->guard, '2026-11-14');
    app(AcceptResignation::class)->handle($resignation, $this->hr, CarbonImmutable::parse('2026-11-14'), null);

    // A guard returns a firearm too; office staff don't.
    expect($resignation->fresh()?->clearanceItems())->toContain(ClearanceItem::FirearmAndEquipment)
        ->and(ClearanceItem::for(isGuard: false))->not->toContain(ClearanceItem::FirearmAndEquipment);

    Livewire::actingAs($this->hr)->test(Resignations::class)
        ->call('open', $resignation->id)
        ->assertSee('₱1,500.00 still owed on loans and load')
        ->call('toggle', ClearanceItem::IdAndUniform->value);
    expect($resignation->fresh()?->isCleared(ClearanceItem::IdAndUniform))->toBeTrue();

    foreach (ClearanceItem::for(isGuard: true) as $item) {
        if ($item !== ClearanceItem::IdAndUniform) {
            app(UpdateClearance::class)->handle($resignation->fresh(), $item, true, $this->hr);
        }
    }

    // Cleared, but still working until the last day: not complete yet.
    expect($resignation->fresh()?->status)->toBe(ResignationStatus::Accepted);
    expect(app(ApplySeparations::class)->handle(CarbonImmutable::parse('2026-11-14')))->toBe(0);

    // The day after: Resigned (account closed), and complete since all is cleared.
    expect(app(ApplySeparations::class)->handle(CarbonImmutable::parse('2026-11-15')))->toBe(1);
    $resignation->refresh();
    expect($this->guard->employeeProfile()->firstOrFail()->employment_status)->toBe(EmploymentStatus::Resigned)
        ->and($resignation->separated_at)->not->toBeNull()
        ->and($resignation->status)->toBe(ResignationStatus::Completed);
    Notification::assertSentTo($this->guard, ResignationNotice::class, fn (ResignationNotice $notice) => $notice->event === ResignationNotice::COMPLETED);

    // Run again: nobody is separated twice.
    expect(app(ApplySeparations::class)->handle(CarbonImmutable::parse('2026-11-16')))->toBe(0);
});

it('lets HR record a paper letter for an employee', function () {
    Livewire::actingAs($this->hr)->test(Resignations::class)
        ->call('startRecording')
        ->set('employeeId', (string) $this->guard->id)
        ->set('reasons', ['family'])
        ->set('letter', 'Handwritten letter handed to the detachment commander.')
        ->set('requestedLastDay', '2026-11-20')
        ->call('record')->assertHasNoErrors();

    expect(Resignation::query()->sole())->user_id->toBe($this->guard->id)->recorded_by->toBe($this->hr->id);
});

it('never takes a resignation from the Executive, filed or recorded', function () {
    expect(fn () => app(FileResignation::class)->handle($this->executive, ResignationReason::Family, null, 'Stepping down from the company at the end of the year.', CarbonImmutable::parse('2026-12-31'), false))
        ->toThrow(ValidationException::class, 'isn\'t filed here');

    $this->actingAs($this->executive)->get(route('resignation'))->assertForbidden();
    Livewire::actingAs($this->hr)->test(Resignations::class)->call('startRecording')
        ->assertDontSee($this->executive->name);
});

it('opens Resignations for HR and the Executive only', function () {
    $this->actingAs($this->hr)->get(route('resignations'))->assertOk();
    $this->actingAs($this->executive)->get(route('resignations'))->assertOk();
    $this->actingAs($this->guard)->get(route('resignations'))->assertForbidden();
    $this->actingAs($this->opsHq)->get(route('resignations'))->assertForbidden();
    $this->actingAs($this->guard)->get(route('resignation'))->assertOk()->assertSee('Resign');
});

it('takes a checklist of reasons, with Others in words and Art. 300(b) just causes', function () {
    // Nothing ticked: refused. Others without words: refused.
    Livewire::actingAs($this->guard)->test(MyResignation::class)
        ->set('letter', 'I am resigning for the reasons ticked above.')->set('acknowledges', true)
        ->call('submit')->assertHasErrors('reasons')
        ->set('reasons', ['pay_and_benefits', 'others'])
        ->call('submit')->assertHasErrors('reasonOther')
        ->set('reasonOther', 'Too far from home')
        ->call('submit')->assertHasNoErrors()
        ->assertSee('Pay and benefits, Too far from home');

    $resignation = Resignation::query()->sole();
    expect($resignation->reasons)->toBe(['pay_and_benefits', 'others'])
        ->and($resignation->reason)->toBe(ResignationReason::PayAndBenefits)
        ->and($resignation->skipsNotice())->toBeFalse();

    // A just cause among them: it becomes the main reason, listed first, and no notice is needed.
    $other = employee();
    $page = Livewire::actingAs($other)->test(MyResignation::class)
        ->set('reasons', ['work_environment', 'inhuman_treatment'])->set('acknowledges', true)
        ->set('lastDay', '2026-10-15');
    expect($page->instance()->needsWaiver())->toBeFalse();
    $page->set('letter', 'My supervisor has shouted at and humiliated me in front of the client every day this week.')
        ->call('submit')->assertHasNoErrors();

    $justCause = Resignation::query()->where('user_id', $other->id)->sole();
    expect($justCause->reason)->toBe(ResignationReason::InhumanTreatment)
        ->and($justCause->reasonLabel())->toBe('Inhuman and unbearable treatment, Work environment')
        ->and($justCause->asks_waiver)->toBeFalse();

    // The old single "just cause" is not offered any more, but older records still read.
    Livewire::actingAs(employee())->test(MyResignation::class)
        ->set('reasons', ['just_cause'])->set('letter', 'An old value sent by hand to the form.')
        ->call('submit')->assertHasErrors('reasons.0');
    $legacy = resign($this, employee(), '2026-10-15', ResignationReason::JustCause);
    expect($legacy->reasonLabel())->toBe('Just cause (leave at once)')->and($legacy->skipsNotice())->toBeTrue();

    // The form shows Art. 300 itself.
    Livewire::actingAs(employee())->test(MyResignation::class)->assertSee('Art. 300. Termination by employee.')->assertSee('Other causes analogous to any of the foregoing.');
});

it('keeps the just causes behind one question, and clears them when it is unticked', function () {
    Livewire::actingAs($this->guard)->test(MyResignation::class)
        ->assertDontSee('Serious insult on my honor and person')
        ->set('claimsJustCause', true)->assertSee('Serious insult on my honor and person')
        ->set('reasons', ['family', 'serious_insult'])
        ->set('claimsJustCause', false)
        ->assertSet('reasons', ['family'])
        ->assertDontSee('Serious insult on my honor and person');
});

it('asks the employee to acknowledge what happens next, and keeps what they read', function () {
    $page = Livewire::actingAs($this->guard)->test(MyResignation::class)
        ->set('reasons', ['another_job'])
        ->set('letter', 'I am resigning to take a job closer to home.')
        ->assertSee('I am resigning of my own free will.')
        ->assertSee('liable for damages (Labor Code Art. 300(a))')
        ->call('submit')->assertHasErrors(['acknowledges' => 'accepted']);
    expect(Resignation::query()->count())->toBe(0);

    $page->set('acknowledges', true)->call('submit')->assertHasNoErrors();
    $resignation = Resignation::query()->sole();
    expect($resignation->acknowledged_at?->toDateTimeString())->toBe('2026-10-15 10:00:00')
        ->and($resignation->acknowledgement)->toContain('I can take this back only until HR accepts it.')
        ->and($resignation->acknowledgement[0])->toBe('I am resigning of my own free will. No one forced me to.')
        // The acknowledgement is the written authorization (Art. 113): what's owed comes out of final pay.
        ->and($resignation->acknowledgement)->toContain('I authorize the company to deduct what I still owe (unpaid loans, borrowed load, unreturned property) from my final pay and cash bond (Labor Code Art. 113).')
        ->and($resignation->deduction_consent_at?->toDateTimeString())->toBe('2026-10-15 10:00:00');

    // A just cause: not "of my own free will", and no notice line.
    $lines = Resignation::acknowledgementLines(true, 'HR');
    expect($lines[0])->toStartWith('What I say in my letter about what happened is true')
        ->and(implode(' ', $lines))->not->toContain('free will')->not->toContain('30 days\' notice');

    // HR sees it; a paper letter HR entered has none.
    Livewire::actingAs($this->hr)->test(Resignations::class)->call('open', $resignation->id)->assertSee('They read and acknowledged 7 points');
    expect(resign($this, employee(), '2026-11-20')->acknowledgement)->toBeNull();
});

it('opens a complaint case for a just cause, linked both ways, for the same handler', function () {
    $resignation = app(FileResignation::class)->handle($this->guard, [ResignationReason::WorkEnvironment, ResignationReason::InhumanTreatment], null, 'My shift leader shouted at me and pushed me on Oct 12 at the post, in front of the client.', CarbonImmutable::today(), false);

    $complaint = $resignation->complaint;
    expect($complaint)->not->toBeNull()
        ->and($complaint->user_id)->toBe($this->guard->id)
        ->and($complaint->resignation_id)->toBe($resignation->id)
        ->and($complaint->for_executive)->toBeFalse()
        ->and($complaint->subject)->toBe('Just cause given for resigning: Inhuman and unbearable treatment')
        ->and($complaint->details)->toContain('shouted at me');
    Notification::assertSentTo($this->hr, ComplaintNotice::class);

    // HR sees the link on the resignation, and the complaint opens from it (?open=).
    Livewire::actingAs($this->hr)->test(Resignations::class)->call('open', $resignation->id)
        ->assertSee($complaint->reference())->assertSee('was opened about what happened');
    Livewire::withQueryParams(['open' => $complaint->id])->actingAs($this->hr)->test(Complaints::class)
        ->assertSet('openedId', $complaint->id)->assertSee('Opened from their resignation for a just cause');

    // No just cause, no case.
    expect(resign($this, employee(), '2026-11-20')->complaint)->toBeNull();

    // An HR member's goes to the Executive, like their resignation.
    $hrMember = staff(Role::Hr);
    $own = app(FileResignation::class)->handle($hrMember, ResignationReason::SeriousInsult, null, 'The operations head insulted me in front of the whole office.', CarbonImmutable::today(), false);
    expect($own->complaint?->for_executive)->toBeTrue();
});

it('fills a sample letter from what was ticked, and refuses one with [brackets] left in', function () {
    $page = Livewire::actingAs($this->guard)->test(MyResignation::class)
        ->set('reasons', ['another_job', 'pay_and_benefits'])
        ->call('useSampleLetter');
    expect($page->get('letter'))->toContain('To HR:')
        ->toContain('My last day of work will be Saturday, November 14, 2026.')
        ->toContain('I am leaving because of another job and pay and benefits.')
        ->toContain($this->guard->name);

    $page->set('claimsJustCause', true)->set('reasons', ['inhuman_treatment'])->set('lastDay', '2026-10-15')->call('useSampleLetter');
    expect($page->get('letter'))->toContain('just cause under Labor Code Art. 300(b): inhuman and unbearable treatment')->toContain('[when, where, who');

    $page->set('acknowledges', true)->call('submit')->assertHasErrors(['letter']);
    expect(Resignation::query()->count())->toBe(0);
});

it('shows HR why people leave over the last 12 months, guards and office apart', function () {
    $office = employee(['is_office_staff' => true]);
    app(FileResignation::class)->handle($this->guard, [ResignationReason::PayAndBenefits, ResignationReason::AnotherJob], null, 'Leaving for a better paying job closer to home.', CarbonImmutable::parse('2026-11-20'), false);
    app(FileResignation::class)->handle($office, [ResignationReason::PayAndBenefits, ResignationReason::Others], 'Too far from home', 'Leaving, the commute is three hours a day.', CarbonImmutable::parse('2026-11-20'), false);
    $withdrawn = resign($this, employee(), '2026-11-20', ResignationReason::Schedule);
    app(WithdrawResignation::class)->handle($withdrawn, $withdrawn->user);

    $summary = app(ResignationReasonsQuery::class)->handle($this->hr);
    expect($summary['filed'])->toBe(2)
        ->and($summary['rows'][0])->toMatchArray(['reason' => ResignationReason::PayAndBenefits, 'guards' => 1, 'office' => 1, 'total' => 2])
        ->and(collect($summary['rows'])->pluck('reason')->all())->not->toContain(ResignationReason::Schedule)
        ->and($summary['others'])->toBe(['Too far from home']);

    Livewire::actingAs($this->hr)->test(Resignations::class)->assertSee('Why people leave')->assertSee('“Too far from home”');
});
