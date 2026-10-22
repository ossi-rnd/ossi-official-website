<?php

use App\Actions\Relievers\AssignReliever;
use App\Enums\AbsenceNoticeStatus;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\Relievers;
use App\Livewire\Attendance\ReportAbsence;
use App\Models\AbsenceNotice;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\AbsenceNoticeFiled;
use App\Notifications\RelieverAssigned;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function deployedGuard(string $name, Region $region = Region::Calabarzon, string $site = 'Metrobank – Imus'): User
{
    $client = Client::query()->firstOrCreate(['region' => $region->value, 'province' => $region->provinces()[0], 'name' => $site]);
    $guard = employee(['client_id' => $client->id, 'detachment' => $client->name, 'post' => 'Main Entrance']);
    $guard->forceFill(['name' => $name])->save();

    return $guard;
}

function opsIn(Role $role, ?Region $region = null): User
{
    $user = staff($role);
    $user->forceFill(['operations_region' => $region])->save();

    return $user;
}

function noticeFor(User $guard, int $daysAhead = 2): AbsenceNotice
{
    Livewire::actingAs($guard)->test(ReportAbsence::class)
        ->set('dutyDate', today()->addDays($daysAhead)->toDateString())
        ->set('reason', 'Fever since last night.')
        ->call('submit')
        ->assertHasNoErrors();

    return AbsenceNotice::query()->where('user_id', $guard->id)->latest('id')->firstOrFail();
}

it('lets a guard tell Operations ahead of time, notifying HQ and their region\'s Satellite only', function () {
    Notification::fake();
    $guard = deployedGuard('Cavite Guard');
    $hq = opsIn(Role::OperationsHq);
    $ownRegion = opsIn(Role::SatelliteOperations, Region::Calabarzon);
    $otherRegion = opsIn(Role::SatelliteOperations, Region::CentralVisayas);

    $notice = noticeFor($guard);

    expect($notice)
        ->status->toBe(AbsenceNoticeStatus::Open)
        ->detachment->toBe('Metrobank – Imus')
        ->post->toBe('Main Entrance');

    Notification::assertSentTo([$hq, $ownRegion], AbsenceNoticeFiled::class);
    Notification::assertNotSentTo($otherRegion, AbsenceNoticeFiled::class);
});

it('refuses past days, the same day twice, and office staff', function () {
    $guard = deployedGuard('Cavite Guard');
    noticeFor($guard);

    Livewire::actingAs($guard)->test(ReportAbsence::class)
        ->set('dutyDate', today()->addDays(2)->toDateString())->set('reason', 'Again the same day')
        ->call('submit')->assertHasErrors('dutyDate')
        ->set('dutyDate', today()->subDay()->toDateString())
        ->call('submit')->assertHasErrors('dutyDate');

    Livewire::actingAs(staff(Role::Hr))->test(ReportAbsence::class)
        ->assertSee('Office staff file a request on My Leave')
        ->set('dutyDate', today()->addDay()->toDateString())->set('reason', 'Not a guard')
        ->call('submit')->assertHasErrors('dutyDate');
});

it('lets Operations assign a free reliever, and tells both guards', function () {
    Notification::fake();
    $absent = deployedGuard('Absent Guard');
    $reliever = deployedGuard('Free Guard', site: 'Metrobank – Dasmariñas');
    $onLeave = deployedGuard('Leave Guard');
    $leave = new LeaveRequest(['type' => 'vacation', 'start_date' => today()->addDays(2), 'end_date' => today()->addDays(2), 'reason' => 'Trip']);
    $leave->user()->associate($onLeave);
    $leave->forceFill(['status' => 'approved'])->save();

    $notice = noticeFor($absent);
    $hq = opsIn(Role::OperationsHq);

    Livewire::actingAs($hq)->test(Relievers::class)
        ->assertSee('Absent Guard')
        ->call('startAssign', $notice->id)
        ->assertSee('Free Guard')
        ->assertDontSeeHtml('>Leave Guard')
        ->set('relieverId', (string) $reliever->id)
        ->set('note', 'Report at 05:45.')
        ->call('assign')
        ->assertHasNoErrors();

    expect($notice->fresh())
        ->status->toBe(AbsenceNoticeStatus::Covered)
        ->reliever_id->toBe($reliever->id)
        ->covered_by->toBe($hq->id);

    Notification::assertSentTo($reliever, RelieverAssigned::class, fn (RelieverAssigned $sent) => $sent->forReliever);
    Notification::assertSentTo($absent, RelieverAssigned::class, fn (RelieverAssigned $sent) => ! $sent->forReliever);

    // On leave that day: refused even if forced.
    expect(fn () => app(AssignReliever::class)->handle($notice, $onLeave, null, $hq))->toThrow(ValidationException::class);
});

it('does not send someone who is already relieving that day', function () {
    $first = noticeFor(deployedGuard('First Absent'));
    $second = noticeFor(deployedGuard('Second Absent'));
    $reliever = deployedGuard('Busy Reliever');
    $hq = opsIn(Role::OperationsHq);

    app(AssignReliever::class)->handle($first, $reliever, null, $hq);

    expect(fn () => app(AssignReliever::class)->handle($second, $reliever, null, $hq))->toThrow(ValidationException::class);
});

it('keeps a Satellite Operation to its own region', function () {
    $cavite = noticeFor(deployedGuard('Cavite Guard'));
    $cebu = noticeFor(deployedGuard('Cebu Guard', Region::CentralVisayas, 'Ayala Center Cebu'));
    $satellite = opsIn(Role::SatelliteOperations, Region::Calabarzon);

    Livewire::actingAs($satellite)->test(Relievers::class)
        ->assertSee('Cavite Guard')->assertDontSee('Cebu Guard')
        ->call('startAssign', $cavite->id)->assertDontSee('Cebu Guard');

    Livewire::actingAs($satellite)->test(Relievers::class)->call('startAssign', $cebu->id)->assertNotFound();
});

it('lets the guard cancel while no reliever is assigned yet', function () {
    $guard = deployedGuard('Cavite Guard');
    $notice = noticeFor($guard);

    Livewire::actingAs($guard)->test(ReportAbsence::class)->call('cancel', $notice->id)->assertHasNoErrors();

    expect($notice->fresh()?->status)->toBe(AbsenceNoticeStatus::Cancelled);
});

it('marks absences whose day passed with no reliever as not covered, and keeps them', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 08:00'));
    $make = function (string $day, AbsenceNoticeStatus $status) {
        $notice = new AbsenceNotice;
        $notice->forceFill(['user_id' => employee()->id, 'duty_date' => $day, 'reason' => 'Sick', 'source' => 'guard', 'detachment' => 'Metrobank – Imus', 'post' => 'Gate', 'status' => $status])->save();

        return $notice;
    };
    $yesterday = $make('2026-10-16', AbsenceNoticeStatus::Open);
    $today = $make('2026-10-17', AbsenceNoticeStatus::Open);
    $covered = $make('2026-10-16', AbsenceNoticeStatus::Covered);

    $this->artisan('app:close-uncovered-absences')->expectsOutput('Marked 1 absence as not covered.')->assertSuccessful();

    expect($yesterday->fresh()?->status)->toBe(AbsenceNoticeStatus::NotCovered)
        ->and($today->fresh()?->status)->toBe(AbsenceNoticeStatus::Open) // today's can still be covered
        ->and($covered->fresh()?->status)->toBe(AbsenceNoticeStatus::Covered)
        ->and(ActivityLog::query()->where('event', 'absence.not_covered')->count())->toBe(1);

    Livewire::actingAs(staff(Role::OperationsHq))->test(Relievers::class)->set('filter', 'not_covered')
        ->assertSee('Not covered')->assertSee($yesterday->user->name)->assertDontSee($today->user->name);
});
