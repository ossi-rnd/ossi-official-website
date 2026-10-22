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

// Office staff's leave and overtime go to the Executive account only:
// only the Executive decides them, sees them, and is told when they're filed.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00'));
    Notification::fake();

    $this->accountant = staff(Role::Accounting);
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

it('sends office staff\'s leave to the Executive only, and a guard\'s up the Operations chain', function () {
    $officeLeave = fileLeave($this, $this->accountant);
    $guardLeave = fileLeave($this, $this->guard);

    Notification::assertSentTo($this->executive, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($officeLeave));
    Notification::assertNotSentTo([$this->hr, $this->opsHq, $this->accountant], LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($officeLeave));
    // The guard has no Satellite Operation for their region, so theirs starts at Operation HQ.
    Notification::assertSentTo($this->opsHq, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($guardLeave));
    Notification::assertNotSentTo($this->executive, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => $notice->leave->is($guardLeave));

    // Only the Executive sees it in the list; HR sees the guard's.
    // The Executive sees both, in two tabs: office staff's (to decide) first, guards' (to watch) next.
    Livewire::actingAs($this->executive)->test(LeaveRequests::class)
        ->assertSee('Olivia Office')->assertDontSee('Gary Guard')
        ->set('group', 'field')->assertSee('Gary Guard')->assertDontSee('Olivia Office');
    Livewire::actingAs($this->hr)->test(LeaveRequests::class)->assertSee('Gary Guard')->assertDontSee('Olivia Office');
    Livewire::actingAs($this->opsHq)->test(LeaveRequests::class)->assertDontSee('Olivia Office');

    // The badge counts what's at each person's step: the guard's is with Operation HQ, not yet HR.
    $badge = fn ($user) => collect(Navigation::for($user))->pluck('items')->flatten(1)->firstWhere('route', 'leave-requests')['badge'];
    expect($badge($this->executive))->toBe(1)->and($badge($this->hr))->toBe(0)->and($badge($this->opsHq))->toBe(1);
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

    Notification::assertSentTo($this->executive, LeaveAwaitingYou::class, function (LeaveAwaitingYou $notice) {
        expect($notice->toArray($this->executive))->toMatchArray([
            'title' => 'Leave to decide: Olivia Office',
            'body' => "Vacation · Oct 12 – Oct 13, 2026 (2 days)\nReason: Family event",
        ]);

        return true;
    });
});

it('has office staff send overtime to an Executive, who alone decides it', function () {
    // Nobody to pick: it goes to the Executive.
    Livewire::actingAs($this->accountant)->test(MyOvertime::class)
        ->assertSee('Goes to the President or Vice President automatically')->assertDontSee('Executive')
        ->set('date', '2026-10-08')->set('hours', '2')->set('reason', 'Payroll cut-off')
        ->call('submit')->assertHasNoErrors();
    $overtime = OvertimeRequest::query()->sole();
    Notification::assertSentTo($this->executive, RequestFiled::class, fn (RequestFiled $notice) => $notice->request->is($overtime));
    Notification::assertNotSentTo([$this->hr, $this->opsHq], RequestFiled::class);

    // HR and Operations don't see it; the Executive approves it.
    Livewire::actingAs($this->hr)->test(OvertimeRequests::class)->assertDontSee('Olivia Office')->call('startReview', $overtime->id)->assertNotFound();
    Livewire::actingAs($this->opsHq)->test(OvertimeRequests::class)->assertDontSee('Olivia Office');
    Livewire::actingAs($this->executive)->test(OvertimeRequests::class)
        ->assertSee('Olivia Office')
        ->call('startReview', $overtime->id)->call('approve')->assertHasNoErrors();

    expect($overtime->fresh()?->status)->toBe(RequestStatus::Approved)
        ->and($overtime->fresh()?->reviewed_by)->toBe($this->executive->id);
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
