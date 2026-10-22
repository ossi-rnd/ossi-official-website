<?php

use App\Actions\Overtime\SubmitOvertimeRequest;
use App\Enums\Region;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Livewire\Admin\LeaveRequests;
use App\Livewire\Admin\OvertimeRequests;
use App\Livewire\Leave\MyLeave;
use App\Livewire\Overtime\MyOvertime;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Notifications\RequestFiled;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00'));
    $this->employee = employee();
    $this->hr = staff(Role::Hr);
});

describe('leave', function () {
    it('lets an employee file leave and cancel it while pending', function () {
        $page = Livewire::actingAs($this->employee)->test(MyLeave::class)
            ->set('type', 'sick')->set('start_date', '2026-10-12')->set('end_date', '2026-10-13')->set('reason', 'Flu')
            ->call('submit')
            ->assertHasNoErrors();

        $leave = LeaveRequest::query()->sole();
        expect($leave->status)->toBe(RequestStatus::Pending)->and($leave->user_id)->toBe($this->employee->id);

        $page->call('cancel', $leave->id);
        expect($leave->fresh()?->status)->toBe(RequestStatus::Cancelled);
    });

    it('refuses overlapping leave and dates in the past', function () {
        $page = Livewire::actingAs($this->employee)->test(MyLeave::class)
            ->set('start_date', '2026-10-12')->set('end_date', '2026-10-14')->set('reason', 'Trip')->call('submit');

        $page->set('start_date', '2026-10-14')->set('end_date', '2026-10-15')->set('reason', 'Again')->call('submit')->assertHasErrors('start_date');
        $page->set('start_date', '2026-10-01')->set('end_date', '2026-10-01')->call('submit')->assertHasErrors('start_date');

        expect(LeaveRequest::query()->count())->toBe(1);
    });

    it('cannot cancel someone else\'s leave', function () {
        Livewire::actingAs($this->employee)->test(MyLeave::class)
            ->set('start_date', '2026-10-12')->set('end_date', '2026-10-12')->set('reason', 'Mine')->call('submit');

        Livewire::actingAs(employee())->test(MyLeave::class)->call('cancel', LeaveRequest::query()->sole()->id)->assertNotFound();
    });

    it('lets HR approve with a note, audited, and blocks a second review', function () {
        $leave = new LeaveRequest(['type' => 'vacation', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'reason' => 'Rest']);
        $leave->user()->associate($this->employee)->save();

        $page = Livewire::actingAs($this->hr)->test(LeaveRequests::class)
            ->call('startReview', $leave->id)->set('reviewNote', 'Enjoy')->call('approve')->assertHasNoErrors();

        expect($leave->fresh())
            ->status->toBe(RequestStatus::Approved)
            ->reviewed_by->toBe($this->hr->id)
            ->review_note->toBe('Enjoy');
        expect(ActivityLog::query()->where('event', 'leave.approved')->where('causer_id', $this->hr->id)->exists())->toBeTrue();

        $page->call('startReview', $leave->id)->call('reject')->assertHasErrors('review');
    });

    it('does not let a supervisor approve their own leave', function () {
        // The Executive decides office staff's leave, so they're the one who could be tempted.
        $executive = staff(Role::President);
        $leave = new LeaveRequest(['type' => 'vacation', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'reason' => 'Mine']);
        $leave->user()->associate($executive)->save();

        Livewire::actingAs($executive)->test(LeaveRequests::class)
            ->call('startReview', $leave->id)->assertHasErrors('list')->assertSet('reviewingId', null)
            // Even forced past the list, the Action refuses.
            ->set('reviewingId', $leave->id)->call('approve')->assertHasErrors('review');
    });
});

describe('overtime', function () {
    it('lets an employee request overtime with no supervisor to pick: it goes to Operations', function () {
        Livewire::actingAs($this->employee)->test(MyOvertime::class)
            ->assertSee('Goes to Operations automatically')->assertDontSee('Select a supervisor')
            ->set('date', '2026-10-08')->set('hours', '2')->set('reason', 'Relief late')
            ->call('submit')
            ->assertHasNoErrors();

        $overtime = OvertimeRequest::query()->sole();
        expect($overtime->requested_minutes)->toBe(120)
            ->and($overtime->supervisor_id)->toBeNull()
            ->and($overtime->sentToLabel())->toBe('Operations');
    });

    it('tells Operation HQ and the guard\'s own region\'s Satellite, not another region\'s', function () {
        Notification::fake();
        $cavite = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
        $guard = employee(['client_id' => $cavite->id]);
        $hq = staff(Role::OperationsHq);
        $mine = staff(Role::SatelliteOperations);
        $mine->forceFill(['operations_region' => Region::Calabarzon->value])->save();
        $other = staff(Role::SatelliteOperations);
        $other->forceFill(['operations_region' => Region::Ncr->value])->save();

        app(SubmitOvertimeRequest::class)->handle($guard, CarbonImmutable::parse('2026-10-08'), 60, 'Relief arrived late.', null);

        Notification::assertSentTo([$hq, $mine], RequestFiled::class);
        Notification::assertNotSentTo([$other, $this->hr], RequestFiled::class);
    });

    it('approves fewer minutes than requested, but never more', function () {
        $overtime = new OvertimeRequest(['date' => '2026-10-08', 'requested_minutes' => 180, 'reason' => 'Event']);
        $overtime->user()->associate($this->employee);
        $overtime->supervisor()->associate($this->hr);
        $overtime->save();

        Livewire::actingAs($this->hr)->test(OvertimeRequests::class)
            ->call('startReview', $overtime->id)->set('approvedHours', '4')->call('approve')->assertHasErrors('approvedHours')
            ->set('approvedHours', '1.5')->call('approve')->assertHasNoErrors();

        expect($overtime->fresh())
            ->status->toBe(RequestStatus::Approved)
            ->approved_minutes->toBe(90);
        expect(ActivityLog::query()->where('event', 'overtime.approved')->exists())->toBeTrue();
    });
});
