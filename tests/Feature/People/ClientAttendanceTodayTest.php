<?php

use App\Enums\AbsenceNoticeStatus;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\Clients;
use App\Models\AbsenceNotice;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use App\Queries\Attendance\ClientAttendanceTodayQuery;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
    $this->day = Shift::query()->create(['name' => 'Day', 'start_time' => '06:00', 'end_time' => '14:00', 'grace_minutes' => 10, 'is_default' => true]);
    $this->late = Shift::query()->create(['name' => 'Afternoon', 'start_time' => '14:00', 'end_time' => '22:00', 'grace_minutes' => 10]);
    $this->night = Shift::query()->create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => 10]);
    $this->site = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);
});

function guardAt(Client $site, Shift $shift, string $name): User
{
    $guard = employee(['client_id' => $site->id, 'detachment' => $site->name, 'post' => 'Gate', 'shift_id' => $shift->id]);
    $guard->forceFill(['name' => $name])->save();

    return $guard;
}

function clockedIn(User $guard, string $at, ?string $out = null, int $late = 0): void
{
    $log = new TimeLog;
    $log->forceFill(['user_id' => $guard->id, 'log_date' => today()->toDateString(), 'clock_in_at' => CarbonImmutable::parse($at), 'clock_out_at' => $out ? CarbonImmutable::parse($out) : null, 'late_minutes' => $late, 'status' => $late > 0 ? 'late' : 'on_time'])->save();
}

it('shows who is on duty, not in, absent and not started at a client', function () {
    $present = guardAt($this->site, $this->day, 'Present Guard');
    clockedIn($present, '2026-10-12 06:05');
    $missing = guardAt($this->site, $this->day, 'Missing Guard');
    $afternoon = guardAt($this->site, $this->late, 'Afternoon Guard');
    $absent = guardAt($this->site, $this->day, 'Absent Guard');

    $notice = new AbsenceNotice;
    $notice->forceFill(['user_id' => $absent->id, 'duty_date' => today()->toDateString(), 'reason' => 'Sick', 'client_id' => $this->site->id, 'post' => 'Gate'])->save();

    $guards = EmployeeProfile::query()->where('client_id', $this->site->id)->with('shift')->get();
    $rows = app(ClientAttendanceTodayQuery::class)->handle($this->site, $guards)['rows'];
    $state = fn (User $user) => $rows[$user->employeeProfile?->id ?? 0]['state'];

    expect($state($present))->toBe('on_duty')
        ->and($state($missing))->toBe('not_in')
        ->and($rows[$missing->employeeProfile?->id ?? 0]['attention'])->toBeTrue()
        ->and($state($afternoon))->toBe('upcoming')
        ->and($state($absent))->toBe('absent_open');

    // Once a reliever is assigned, the absent guard's post is covered.
    $reliever = employee();
    $notice->forceFill(['status' => AbsenceNoticeStatus::Covered, 'reliever_id' => $reliever->id])->save();

    $result = app(ClientAttendanceTodayQuery::class)->handle($this->site, $guards);
    expect($result['rows'][$absent->employeeProfile?->id ?? 0]['state'])->toBe('absent_covered')
        ->and($result['relief'][0]['for'])->toBe('Absent Guard');
});

it('counts a night guard against last night\'s shift until it ends', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 03:00'));
    $night = guardAt($this->site, $this->night, 'Night Guard');

    $log = new TimeLog;
    $log->forceFill(['user_id' => $night->id, 'log_date' => '2026-10-11', 'clock_in_at' => CarbonImmutable::parse('2026-10-11 21:55'), 'late_minutes' => 0, 'status' => 'on_time'])->save();

    $guards = EmployeeProfile::query()->where('client_id', $this->site->id)->with('shift')->get();
    $rows = app(ClientAttendanceTodayQuery::class)->handle($this->site, $guards)['rows'];

    expect($rows[$night->employeeProfile?->id ?? 0]['state'])->toBe('on_duty');
});

it('shows today\'s attendance and warns about uncovered posts in the guards list', function () {
    guardAt($this->site, $this->day, 'Missing Guard');
    clockedIn(guardAt($this->site, $this->day, 'Present Guard'), '2026-10-12 06:20', late: 20);

    Livewire::actingAs(staff(Role::SuperAdmin))->test(Clients::class)
        ->call('viewGuards', $this->site->id)
        ->assertSee('No clock-in')
        ->assertSee('On duty')
        ->assertSee('20 min late')
        ->assertSee('may be uncovered');
});
