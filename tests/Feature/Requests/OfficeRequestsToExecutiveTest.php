<?php

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Livewire\Admin\LeaveRequests;
use App\Livewire\Admin\OvertimeRequests;
use App\Livewire\Leave\MyLeave;
use App\Livewire\Overtime\MyOvertime;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Notifications\LeaveAwaitingYou;
use App\Notifications\RequestFiled;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// Office staff's leave and overtime go to their head (App\Support\Heads, the owner's chain):
// HR for Admin and others; the President or VP for HR, Operation HQ and Accounting. The
// President or VP still SEE every office staffer's, and HR every non-HR staffer's (they monitor).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00'));
    Notification::fake();

    // An Admin staffer: HR is their head.
    $this->accountant = staff(Role::Admin);
    $this->accountant->forceFill(['name' => 'Olivia Office'])->save();
    $this->guard = employee();
    $this->guard->forceFill(['name' => 'Gary Guard'])->save();

    $this->executive = staff(Role::President);
    $this->executive->forceFill(['name' => 'Ramon Executive'])->save();
    $this->hr = staff(Role::Hr);
    $this->hr->forceFill(['name' => 'Hannah HR'])->save();
    $this->opsHq = staff(Role::OperationsHq);
    $this->opsHq->forceFill(['name' => 'Oscar Ops'])->save();
});

function fileLeave(object $test, $user): LeaveRequest
{
    Livewire::actingAs($user)->test(MyLeave::class)
        ->set('type', 'vacation')->set('start_date', '2026-10-12')->set('end_date', '2026-10-13')->set('reason', 'Family event')
        ->call('submit')->assertHasNoErrors();

    return LeaveRequest::query()->where('user_id', $user->id)->sole();
}

it('sends an Admin staffer\'s leave to HR, their head, and a guard\'s up the Operations chain', function () {
    $officeLeave = fileLeave($this, $this->accountant);
    $guardLeave = fileLeave($this, $this->guard);

    Notification::assertSentTo($this->hr, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($officeLeave));
    Notification::assertNotSentTo([$this->executive, $this->opsHq, $this->accountant], LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($officeLeave));
    // The guard has no Satellite Operation for their region, so theirs starts at Operation HQ.
    Notification::assertSentTo($this->opsHq, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($guardLeave));
    Notification::assertNotSentTo($this->executive, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($guardLeave));

    // HR decides the clerk's (and sees the guards'); the President or VP still sees it (monitors), in the office tab.
    Livewire::actingAs($this->hr)->test(LeaveRequests::class)->assertSee('Gary Guard')
        ->call('showGroup', 'office')->assertSee('Olivia Office')
        ->call('startReview', $officeLeave->id)->assertHasNoErrors()->assertSet('reviewingId', $officeLeave->id);
    Livewire::actingAs($this->executive)->test(LeaveRequests::class)->assertSee('Olivia Office')->assertSee('View only');
    Livewire::actingAs($this->opsHq)->test(LeaveRequests::class)->assertDontSee('Olivia Office');

    // The badge counts what's at each person's step: the clerk's is with HR; the guard's with Operation HQ.
    $badge = fn ($user) => collect(Navigation::for($user))->pluck('items')->flatten(1)->firstWhere('route', 'leave-requests')['badge'];
    expect($badge($this->executive))->toBe(0)->and($badge($this->hr))->toBe(1)->and($badge($this->opsHq))->toBe(1);
});

it('sends a Satellite Operation\'s leave and overtime to Operation HQ, who decides them', function () {
    $satellite = staff(Role::SatelliteOperations);
    $satellite->forceFill(['name' => 'Sam Satellite', 'operations_region' => 'ncr'])->save();

    $leave = fileLeave($this, $satellite);
    expect($leave->stage?->value)->toBe('hq');
    Notification::assertSentTo($this->opsHq, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($leave));
    Notification::assertNotSentTo([$this->hr, $this->executive], LeaveAwaitingYou::class);

    // Operation HQ decides it outright (no "Endorse" on to HR, as for a guard).
    Livewire::actingAs($this->opsHq)->test(LeaveRequests::class)->call('showGroup', 'office')->assertSee('Sam Satellite')
        ->call('startReview', $leave->id)->set('reviewNote', 'Enjoy')->call('approve')->assertHasNoErrors();
    expect($leave->fresh()?->status)->toBe(RequestStatus::Approved)->and($leave->fresh()?->reviewed_by)->toBe($this->opsHq->id);

    Livewire::actingAs($satellite)->test(MyOvertime::class)->assertSee('Goes to Operation HQ automatically')
        ->set('date', '2026-10-08')->set('hours', '2')->set('reason', 'Regional visit')->call('submit')->assertHasNoErrors();
    Notification::assertSentTo($this->opsHq, RequestFiled::class);
    Notification::assertNotSentTo($this->hr, RequestFiled::class);
});

it('sends HR staff\'s own leave and overtime to the Executive, never to another HR member', function () {
    $otherHr = staff(Role::Hr);
    $leave = fileLeave($this, $this->hr);

    Livewire::actingAs($this->hr)->test(MyOvertime::class)
        ->set('date', '2026-10-08')->set('hours', '2')->set('reason', 'Payroll cut-off')
        ->call('submit')->assertHasNoErrors();
    $overtime = OvertimeRequest::query()->sole();

    Notification::assertSentTo($this->executive, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($leave));
    Notification::assertSentTo($this->executive, RequestFiled::class, fn (RequestFiled $notice) => $notice->request->is($overtime));
    Notification::assertNothingSentTo($otherHr);

    // The other HR member doesn't see either, let alone decide them.
    Livewire::actingAs($otherHr)->test(LeaveRequests::class)->assertDontSee('Hannah HR');
    Livewire::actingAs($otherHr)->test(OvertimeRequests::class)->assertDontSee('Hannah HR')->call('startReview', $overtime->id)->assertNotFound();
});

it('switches tabs with showGroup, and an unknown tab falls back to the default', function () {
    fileLeave($this, $this->accountant);
    fileLeave($this, $this->guard);

    Livewire::actingAs($this->executive)->test(LeaveRequests::class)
        ->assertSee('Olivia Office')->assertDontSee('Gary Guard')
        ->call('showGroup', 'field')->assertSet('group', 'field')->assertSee('Gary Guard')->assertDontSee('Olivia Office')
        ->call('showGroup', 'nonsense')->assertSet('group', '')->assertSee('Olivia Office');
});

it('words the bell item as a short title and one fact per line', function () {
    $leave = fileLeave($this, $this->accountant);

    Notification::assertSentTo($this->hr, LeaveAwaitingYou::class, function (LeaveAwaitingYou $notice) {
        expect($notice->toArray($this->hr))->toMatchArray([
            'title' => 'Leave to decide: Olivia Office',
            'body' => "Vacation · Oct 12 – Oct 13, 2026 (2 days)\nReason: Family event",
        ]);

        return true;
    });
});

it('sends Accounting\'s and Operation HQ\'s leave and overtime to the President or VP, with HR watching', function () {
    $cashier = staff(Role::Accounting);
    $cashier->forceFill(['name' => 'Carla Cashier'])->save();
    $leave = fileLeave($this, $cashier);
    $opsLeave = fileLeave($this, $this->opsHq);

    expect($leave->stage?->value)->toBe('executive')->and($opsLeave->stage?->value)->toBe('executive');
    Notification::assertSentTo($this->executive, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($leave));
    Notification::assertSentTo($this->executive, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($opsLeave));
    Notification::assertNotSentTo($this->hr, LeaveAwaitingYou::class);

    // HR sees both (monitor only) and can't decide them.
    Livewire::actingAs($this->hr)->test(LeaveRequests::class)->call('showGroup', 'office')
        ->assertSee('Carla Cashier')->assertSee('Oscar Ops')->assertSee('View only')
        ->call('startReview', $leave->id)->assertHasErrors('list');

    Livewire::actingAs($cashier)->test(MyOvertime::class)->assertSee('Goes to the President or Vice President automatically')
        ->set('date', '2026-10-08')->set('hours', '2')->set('reason', 'Month-end close')->call('submit')->assertHasNoErrors();
    Notification::assertSentTo($this->executive, RequestFiled::class);
    Notification::assertNotSentTo($this->hr, RequestFiled::class);

    Livewire::actingAs($this->executive)->test(LeaveRequests::class)
        ->call('startReview', $leave->id)->call('approve')->assertHasNoErrors();
    expect($leave->fresh()?->reviewed_by)->toBe($this->executive->id);
});

it('has an Admin staffer send overtime to HR, their head, who decides it', function () {
    // Nobody to pick: it goes to their head.
    Livewire::actingAs($this->accountant)->test(MyOvertime::class)
        ->assertSee('Goes to HR automatically')->assertDontSee('Executive')
        ->set('date', '2026-10-08')->set('hours', '2')->set('reason', 'Payroll cut-off')
        ->call('submit')->assertHasNoErrors();
    $overtime = OvertimeRequest::query()->sole();
    Notification::assertSentTo($this->hr, RequestFiled::class, fn (RequestFiled $notice) => $notice->request->is($overtime));
    Notification::assertNotSentTo([$this->executive, $this->opsHq], RequestFiled::class);

    // Operations doesn't see it; the President or VP sees it but can't decide it; HR approves it.
    Livewire::actingAs($this->opsHq)->test(OvertimeRequests::class)->assertDontSee('Olivia Office');
    Livewire::actingAs($this->executive)->test(OvertimeRequests::class)->assertSee('Olivia Office')->call('startReview', $overtime->id)->assertHasErrors('list');
    Livewire::actingAs($this->hr)->test(OvertimeRequests::class)
        ->call('showGroup', 'office')->assertSee('Olivia Office')
        ->call('startReview', $overtime->id)->call('approve')->assertHasNoErrors();

    expect($overtime->fresh()?->status)->toBe(RequestStatus::Approved)
        ->and($overtime->fresh()?->reviewed_by)->toBe($this->hr->id);
});

it('keeps guards\' overtime with Operations, HR or Admin', function () {
    Livewire::actingAs($this->guard)->test(MyOvertime::class)
        ->assertSee('Goes to Operations automatically')
        ->set('date', '2026-10-08')->set('hours', '1')->set('reason', 'Relief late')
        ->call('submit')->assertHasNoErrors();

    // Operations hears it; HR and the Executive aren't pinged.
    $overtime = OvertimeRequest::query()->sole();
    Notification::assertSentTo($this->opsHq, RequestFiled::class, fn (RequestFiled $notice) => $notice->request->is($overtime));
    Notification::assertNotSentTo([$this->hr, $this->guard], RequestFiled::class);
    Notification::assertNothingSentTo($this->executive);
    expect(collect(Navigation::for($this->executive))->pluck('items')->flatten(1)->firstWhere('route', 'overtime-requests')['badge'])->toBe(0)
        ->and(collect(Navigation::for($this->opsHq))->pluck('items')->flatten(1)->firstWhere('route', 'overtime-requests')['badge'])->toBe(1);
});
