<?php

use App\Actions\Attendance\RecordPunch;
use App\Actions\Relievers\AssignReliever;
use App\Actions\Relievers\CancelAbsenceNotice;
use App\Actions\Relievers\DetectNoShows;
use App\Actions\Relievers\SubmitAbsenceNotice;
use App\Enums\AbsenceNoticeStatus;
use App\Enums\AbsenceSource;
use App\Enums\PunchType;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Relievers;
use App\Models\AbsenceNotice;
use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use App\Notifications\NoShowAlert;
use App\Queries\Operations\ReliefCandidatesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// A guard who forgot to report: caught when the grace period ends, Operations
// alerted (with who could hold the post), escalated if nobody is sent, and
// closed by itself if the guard turns up after all.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 05:00')); // a Monday
    $this->day = Shift::query()->create(['name' => 'Day Duty', 'start_time' => '06:00', 'end_time' => '15:00', 'grace_minutes' => 10]);
    $this->night = Shift::query()->create(['name' => 'Night Duty', 'start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => 10]);
    $this->imus = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $this->guard = noShowGuard('Juan Dela Cruz', $this->imus, ['contact_number' => '0917 555 0101']);
    $this->duty = rostered($this->guard, '2026-10-12', $this->day, $this->imus, 'Main Entrance');

    $this->hq = staff(Role::OperationsHq);
    $this->satellite = staff(Role::SatelliteOperations);
    $this->satellite->forceFill(['operations_region' => Region::Calabarzon])->save();
    $this->otherSatellite = staff(Role::SatelliteOperations);
    $this->otherSatellite->forceFill(['operations_region' => Region::CentralVisayas])->save();
});

/** @param array<string, mixed> $profile */
function noShowGuard(string $name, Client $client, array $profile = []): User
{
    $guard = employee(['client_id' => $client->id, 'detachment' => $client->name, 'post' => 'Main Entrance', ...$profile]);
    $guard->forceFill(['name' => $name])->save();

    return $guard;
}

function rostered(User $guard, string $date, Shift $shift, Client $client, ?string $post = null): DutyAssignment
{
    $duty = new DutyAssignment;
    $duty->forceFill(['user_id' => $guard->id, 'duty_date' => $date, 'shift_id' => $shift->id, 'client_id' => $client->id, 'post' => $post])->save();

    return $duty;
}

function detectAt(string $at): array
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(DetectNoShows::class)->handle(CarbonImmutable::now());
}

it('flags a guard who neither clocked in nor reported, once the grace period ends, and alerts the right Operations', function () {
    Notification::fake();

    expect(detectAt('2026-10-12 06:10')['noShows'])->toBe(0); // still within grace

    $this->travelTo(CarbonImmutable::parse('2026-10-12 06:11'));
    $this->artisan('app:detect-no-shows')->expectsOutputToContain('1 no-show, 0 escalations')->assertSuccessful();

    $notice = AbsenceNotice::query()->sole();
    expect($notice)
        ->source->toBe(AbsenceSource::NoShow)
        ->status->toBe(AbsenceNoticeStatus::Open)
        ->duty_assignment_id->toBe($this->duty->id)
        ->post->toBe('Main Entrance')
        ->reason->toBe('Duty from 6:00 AM: didn\'t clock in or report.');

    Notification::assertSentTo([$this->hq, $this->satellite], NoShowAlert::class, function (NoShowAlert $alert) {
        $data = $alert->toArray($this->hq);

        return $alert->kind === NoShowAlert::DETECTED
            && $data['title'] === 'No-show: Juan Dela Cruz · Metrobank – Imus · Main Entrance'
            && str_contains($data['body'], 'No clock-in and no notice for the Day Duty that started 6:00 AM (grace ended 6:10 AM).');
    });
    Notification::assertNotSentTo($this->otherSatellite, NoShowAlert::class);

    // Running again changes nothing.
    expect(detectAt('2026-10-12 06:16')['noShows'])->toBe(0)
        ->and(AbsenceNotice::query()->count())->toBe(1);
});

it('names the guard still at the post, who could stay until a reliever arrives', function () {
    Notification::fake();
    $nightGuard = noShowGuard('Pedro Reyes', $this->imus);
    $lastNight = rostered($nightGuard, '2026-10-11', $this->night, $this->imus, 'Main Entrance');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-11'))->create(['user_id' => $nightGuard->id, 'duty_assignment_id' => $lastNight->id, 'shift_id' => $this->night->id, 'clock_in_at' => '2026-10-11 21:55:00', 'clock_out_at' => null]);

    detectAt('2026-10-12 06:11');

    Notification::assertSentTo($this->hq, NoShowAlert::class, fn (NoShowAlert $alert) => $alert->holdover === 'Pedro Reyes (Night Duty, until 6:00 AM, Main Entrance)'
        && str_contains($alert->toArray($this->hq)['body'], 'Pedro Reyes (Night Duty, until 6:00 AM, Main Entrance) is still clocked in there: ask them to stay until the reliever arrives.'));
});

it('leaves alone a guard who clocked in, reported, is on leave, isn\'t on the roster, or whose shift is over', function () {
    Notification::fake();
    $site = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Bacoor']);

    $clockedIn = noShowGuard('Clocked In', $site);
    $log = rostered($clockedIn, '2026-10-12', $this->day, $site);
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-12'))->create(['user_id' => $clockedIn->id, 'duty_assignment_id' => $log->id, 'clock_in_at' => '2026-10-12 06:20:00', 'clock_out_at' => null]);

    $reported = noShowGuard('Reported', $site);
    rostered($reported, '2026-10-12', $this->day, $site);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 05:30'));
    app(SubmitAbsenceNotice::class)->handle($reported, CarbonImmutable::today(), 'Fever.');

    $onLeave = noShowGuard('On Leave', $site);
    rostered($onLeave, '2026-10-12', $this->day, $site);
    $leave = new LeaveRequest(['type' => 'vacation', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'reason' => 'Trip']);
    $leave->user()->associate($onLeave);
    $leave->forceFill(['status' => 'approved'])->save();

    noShowGuard('Never Rostered', $site); // on their usual shift: a rest day can't be told apart

    expect(detectAt('2026-10-12 06:30')['noShows'])->toBe(1) // only Juan
        ->and(AbsenceNotice::query()->where('source', AbsenceSource::NoShow)->pluck('user_id')->all())->toBe([$this->guard->id]);

    // After the shift there's nothing left to cover.
    $late = noShowGuard('Missed Yesterday', $site);
    rostered($late, '2026-10-11', $this->day, $site);
    expect(AbsenceNotice::query()->where('user_id', $late->id)->exists())->toBeFalse();
});

it('alerts Operation HQ again, once, when the post is still uncovered 30 minutes later', function () {
    Notification::fake();
    detectAt('2026-10-12 06:11');

    // Raised at 6:11, so 30 minutes from then.
    expect(detectAt('2026-10-12 06:40')['escalated'])->toBe(0)
        ->and(detectAt('2026-10-12 06:41')['escalated'])->toBe(1)
        ->and(detectAt('2026-10-12 06:45')['escalated'])->toBe(0);

    Notification::assertSentTo($this->hq, NoShowAlert::class, fn (NoShowAlert $alert) => $alert->kind === NoShowAlert::ESCALATED
        && $alert->toArray($this->hq)['title'] === 'Still uncovered: Metrobank – Imus · Main Entrance (Juan Dela Cruz no-show)');
    Notification::assertNotSentTo($this->satellite, NoShowAlert::class, fn (NoShowAlert $alert) => $alert->kind === NoShowAlert::ESCALATED);
});

it('does not escalate once a reliever is on the way', function () {
    Notification::fake();
    detectAt('2026-10-12 06:11');
    $reliever = noShowGuard('Ramon Free', $this->imus);

    app(AssignReliever::class)->handle(AbsenceNotice::query()->sole(), $reliever, null, $this->hq);

    expect(detectAt('2026-10-12 06:45'))->toBe(['noShows' => 0, 'escalated' => 0]);

    // The reliever, sent at 6:11, has an hour to get there; after that they're a no-show too.
    expect(detectAt('2026-10-12 07:11')['noShows'])->toBe(0)
        ->and(detectAt('2026-10-12 07:12')['noShows'])->toBe(1)
        ->and(AbsenceNotice::query()->latest('id')->first()?->reason)->toBe('Duty from 6:00 AM: sent as a reliever, but didn\'t clock in.');
});

it('closes the no-show when the guard turns up after all, and tells Operations', function () {
    Notification::fake();
    detectAt('2026-10-12 06:11');

    $this->travelTo(CarbonImmutable::parse('2026-10-12 06:40'));
    app(RecordPunch::class)->handle($this->guard, PunchType::ClockIn, cameraPhoto(), 14.43, 120.94, 10);

    $notice = AbsenceNotice::query()->sole();
    expect($notice->status)->toBe(AbsenceNoticeStatus::Arrived)
        ->and($notice->arrived_at?->format('H:i'))->toBe('06:40');

    Notification::assertSentTo($this->hq, NoShowAlert::class, fn (NoShowAlert $alert) => $alert->kind === NoShowAlert::ARRIVED
        && $alert->toArray($this->hq)['body'] === 'Juan Dela Cruz clocked in at 6:40 AM (duty started 6:00 AM). Nobody needs sending.');

    // Off the board, and no escalation for it.
    expect(AbsenceNotice::query()->needsReliever()->count())->toBe(0)
        ->and(detectAt('2026-10-12 06:45')['escalated'])->toBe(0);
});

it('keeps the reliever when the guard turns up after one was sent, and asks Operations to decide', function () {
    Notification::fake();
    detectAt('2026-10-12 06:11');
    $reliever = noShowGuard('Ramon Free', $this->imus);
    app(AssignReliever::class)->handle(AbsenceNotice::query()->sole(), $reliever, null, $this->hq);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 06:50'));
    app(RecordPunch::class)->handle($this->guard, PunchType::ClockIn, cameraPhoto(), 14.43, 120.94, 10);

    expect(AbsenceNotice::query()->sole()->status)->toBe(AbsenceNoticeStatus::Covered);
    Notification::assertSentTo($this->hq, NoShowAlert::class, fn (NoShowAlert $alert) => $alert->kind === NoShowAlert::ARRIVED_COVERED
        && str_contains($alert->toArray($this->hq)['body'], 'but Ramon Free was already sent. Decide who stays.'));
});

it('takes the guard\'s reason when they report late, and doesn\'t let them cancel a no-show', function () {
    Notification::fake();
    detectAt('2026-10-12 06:11');
    $this->travelTo(CarbonImmutable::parse('2026-10-12 06:25'));

    app(SubmitAbsenceNotice::class)->handle($this->guard, CarbonImmutable::today(), 'Motorcycle broke down in Bacoor.');

    $notice = AbsenceNotice::query()->sole();
    expect($notice->source)->toBe(AbsenceSource::NoShow)
        ->and($notice->reason)->toBe('Duty from 6:00 AM: didn\'t clock in or report. Reported at 6:25 AM: Motorcycle broke down in Bacoor.')
        ->and(fn () => app(CancelAbsenceNotice::class)->handle($notice, $this->guard))->toThrow(ValidationException::class, 'Operations raised this when you didn\'t clock in.');
});

it('offers the nearest rested guards: same site, same province, same region; never the one just off a night shift', function () {
    Notification::fake();
    $bacoor = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Bacoor']);
    $calamba = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Laguna', 'name' => 'Metrobank – Calamba']);
    $cebu = Client::query()->create(['region' => Region::CentralVisayas, 'province' => 'Cebu', 'name' => 'Metrobank – Cebu']);

    $region = noShowGuard('A Same Region', $calamba);
    $province = noShowGuard('B Same Province', $bacoor);
    $site = noShowGuard('C Same Site', $this->imus);
    $elsewhere = noShowGuard('D Elsewhere', $cebu);
    $tired = noShowGuard('E Night Guard', $this->imus);
    rostered($tired, '2026-10-11', $this->night, $this->imus);

    detectAt('2026-10-12 06:11');
    $notice = AbsenceNotice::query()->sole();
    $candidates = app(ReliefCandidatesQuery::class)->handle($notice, $this->hq);

    expect($candidates->pluck('name')->all())->toBe(['C Same Site', 'B Same Province', 'A Same Region', 'D Elsewhere'])
        ->and(ReliefCandidatesQuery::closeness($province, $notice))->toBe(ReliefCandidatesQuery::SAME_PROVINCE);
});

it('puts no-shows on the Relievers board with a number to call and who could hold the post', function () {
    Notification::fake();
    $nightGuard = noShowGuard('Pedro Reyes', $this->imus);
    $lastNight = rostered($nightGuard, '2026-10-11', $this->night, $this->imus, 'Main Entrance');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-11'))->create(['user_id' => $nightGuard->id, 'duty_assignment_id' => $lastNight->id, 'clock_in_at' => '2026-10-11 21:55:00', 'clock_out_at' => null]);
    noShowGuard('Ramon Free', $this->imus, ['contact_number' => '0918 222 3344']);
    detectAt('2026-10-12 06:11');

    Livewire::actingAs($this->hq)->test(Relievers::class)
        ->assertSee('No-show')
        ->assertSee('Duty from 6:00 AM: didn&#039;t clock in or report.', false)
        ->call('startAssign', AbsenceNotice::query()->sole()->id)
        ->assertSee('Juan Dela Cruz didn\'t show up')
        ->assertSeeHtml('href="tel:09175550101"')
        ->assertSee('Still at the site:')
        ->assertSee('Pedro Reyes (Night Duty, until 6:00 AM, Main Entrance)')
        ->assertSee('Ramon Free')
        ->assertSee('Same site')
        ->assertSeeHtml('href="tel:09182223344"')
        ->assertDontSeeHtml('for="candidate-'.$nightGuard->id.'"'); // on duty, not rested
});

it('keeps no-shows apart from reported absences on the guard\'s record', function () {
    Notification::fake();
    detectAt('2026-10-12 06:11');
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00'));
    app(SubmitAbsenceNotice::class)->handle($this->guard, CarbonImmutable::parse('2026-10-14'), 'Medical check-up.');

    Livewire::actingAs($this->hq)->test(EmployeeDetail::class, ['profile' => $this->guard->employeeProfile()->firstOrFail()])
        ->assertSee('Absences · last 90 days')
        ->assertSee('1 reported ahead · 1 not reported (no-show)')
        ->assertSee('Not reported');
});
