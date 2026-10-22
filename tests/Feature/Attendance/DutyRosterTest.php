<?php

use App\Actions\Attendance\RecordPunch;
use App\Actions\Relievers\AssignReliever;
use App\Actions\Roster\RecordManualAttendance;
use App\Enums\PunchType;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\DutyRoster;
use App\Models\AbsenceNotice;
use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\PatrolCheckpoint;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// The post: Makati CBD, geofence 150 m around these coordinates.
const POST_LAT = 14.5547;
const POST_LNG = 121.0244;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 05:30')); // a Monday
    $this->dayDuty = Shift::query()->create(['name' => 'Day Duty', 'start_time' => '06:00', 'end_time' => '15:00', 'grace_minutes' => 10]);
    $this->nightDuty = Shift::query()->create(['name' => 'Night Duty', 'start_time' => '22:00', 'end_time' => '07:00', 'grace_minutes' => 10]);
    $this->site = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD', 'latitude' => POST_LAT, 'longitude' => POST_LNG, 'geofence_meters' => 150]);
    $this->guard = employee(['client_id' => $this->site->id, 'detachment' => 'Makati CBD', 'post' => 'Lobby']);
    $this->ops = staff(Role::OperationsHq);
});

function duty(User $guard, string $date, Shift $shift, ?Client $client = null, ?string $post = 'Lobby'): DutyAssignment
{
    $duty = new DutyAssignment;
    $duty->forceFill(['user_id' => $guard->id, 'duty_date' => $date, 'shift_id' => $shift->id, 'client_id' => $client?->id ?? test()->site->id, 'post' => $post])->save();

    return $duty;
}

function punchAt(User $guard, PunchType $type, float $lat = POST_LAT, float $lng = POST_LNG, ?int $accuracy = 10): TimeLog
{
    return app(RecordPunch::class)->handle($guard, $type, cameraPhoto(), $lat, $lng, $accuracy, 'Ayala Ave.');
}

describe('clocking in against the roster', function () {
    it('allows an early clock-in on a rostered duty, without counting it as worked time', function () {
        $duty = duty($this->guard, '2026-10-12', $this->dayDuty);

        $log = punchAt($this->guard, PunchType::ClockIn);

        expect($log->duty_assignment_id)->toBe($duty->id)
            ->and($log->shift_id)->toBe($this->dayDuty->id)
            ->and($log->late_minutes)->toBe(0);

        // Out at the end: no undertime, and the 30 early minutes aren't night-differential or worked-time padding.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00'));
        $log = punchAt($this->guard, PunchType::ClockOut);
        expect($log->undertime_minutes)->toBe(0);
    });

    it('measures lateness from the duty, not a fixed shift', function () {
        duty($this->guard, '2026-10-12', $this->dayDuty);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 06:25'));

        expect(punchAt($this->guard, PunchType::ClockIn)->late_minutes)->toBe(15);
    });

    it('refuses a clock-in more than an hour before the duty', function () {
        duty($this->guard, '2026-10-12', $this->dayDuty);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 04:30'));

        expect(fn () => punchAt($this->guard, PunchType::ClockIn))->toThrow(ValidationException::class, 'You can clock in from 5:00 AM');
    });

    it('refuses a clock-in on a day off, and says when the next duty is', function () {
        duty($this->guard, '2026-10-13', $this->dayDuty);

        expect(fn () => punchAt($this->guard, PunchType::ClockIn))->toThrow(ValidationException::class, 'You have no duty today')
            ->and(fn () => punchAt($this->guard, PunchType::ClockIn))->toThrow(ValidationException::class, 'Tue, Oct 13 at 6:00 AM');
    });

    it('counts a late clock-in after midnight against last night\'s duty', function () {
        duty($this->guard, '2026-10-11', $this->nightDuty);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 00:30'));

        $log = punchAt($this->guard, PunchType::ClockIn);

        expect($log->log_date->toDateString())->toBe('2026-10-11')
            ->and($log->late_minutes)->toBe(140); // 22:10 grace → 00:30

        $this->travelTo(CarbonImmutable::parse('2026-10-12 07:00'));
        expect(punchAt($this->guard, PunchType::ClockOut)->clock_out_at?->format('H:i'))->toBe('07:00');
    });

    it('keeps the usual shift for a guard who was never rostered', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:05'));
        $unrostered = employee();

        expect(punchAt($unrostered, PunchType::ClockIn)->duty_assignment_id)->toBeNull();
    });
});

describe('anti-cheating', function () {
    it('refuses a clock-in or clock-out away from the post', function () {
        duty($this->guard, '2026-10-12', $this->dayDuty);

        // ~2.5 km away (Pasong Tamo).
        expect(fn () => punchAt($this->guard, PunchType::ClockIn, 14.5530, 121.0010))->toThrow(ValidationException::class, 'from Makati CBD. Clock in at your post.');
        expect(TimeLog::query()->count())->toBe(0);

        punchAt($this->guard, PunchType::ClockIn);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00'));

        expect(fn () => punchAt($this->guard, PunchType::ClockOut, 14.5530, 121.0010))->toThrow(ValidationException::class, 'Clock out at your post.');
    });

    it('allows the phone\'s stated GPS error at the edge of the fence, but not a vague fix', function () {
        duty($this->guard, '2026-10-12', $this->dayDuty);

        // ~180 m out on a 150 m fence, ±40 m: could be inside.
        punchAt($this->guard, PunchType::ClockIn, POST_LAT + 0.00162, POST_LNG, 40);
        expect(TimeLog::query()->count())->toBe(1);

        $other = employee(['client_id' => $this->site->id]);
        duty($other, '2026-10-12', $this->dayDuty);
        expect(fn () => punchAt($other, PunchType::ClockIn, POST_LAT, POST_LNG, 350))->toThrow(ValidationException::class, 'GPS fix is too rough');
    });

    it('lets a reliever clock in at the absent guard\'s post', function () {
        $elsewhere = Client::query()->create(['region' => Region::Ncr, 'province' => 'Taguig', 'name' => 'BGC Tower One', 'latitude' => 14.5509, 'longitude' => 121.0503]);
        $reliever = employee(['client_id' => $elsewhere->id, 'shift_id' => $this->dayDuty->id]);
        duty($this->guard, '2026-10-12', $this->dayDuty);

        $notice = new AbsenceNotice;
        $notice->forceFill(['user_id' => $this->guard->id, 'duty_date' => '2026-10-12', 'reason' => 'Fever', 'client_id' => $this->site->id, 'detachment' => 'Makati CBD', 'post' => 'Lobby'])->save();

        app(AssignReliever::class)->handle($notice, $reliever, null, $this->ops);

        $relief = DutyAssignment::query()->where('user_id', $reliever->id)->sole();
        expect($relief->absence_notice_id)->toBe($notice->id)->and($relief->client_id)->toBe($this->site->id)->and($relief->shift_id)->toBe($this->dayDuty->id);

        // At Makati CBD (the absent guard's post), not at their own BGC post.
        expect(punchAt($reliever, PunchType::ClockIn)->duty_assignment_id)->toBe($relief->id);
    });

    it('does not offer a guard who has their own duty that day as a reliever', function () {
        $busy = employee(['client_id' => $this->site->id]);
        duty($busy, '2026-10-12', $this->dayDuty);
        duty($this->guard, '2026-10-12', $this->dayDuty);

        $notice = new AbsenceNotice;
        $notice->forceFill(['user_id' => $this->guard->id, 'duty_date' => '2026-10-12', 'reason' => 'Fever', 'client_id' => $this->site->id])->save();

        expect(fn () => app(AssignReliever::class)->handle($notice, $busy, null, $this->ops))->toThrow(ValidationException::class);
    });
});

describe('the Duty Roster page', function () {
    it('lets Operations set a duty, and refuses past days and clocked-in duties', function () {
        Livewire::actingAs($this->ops)->test(DutyRoster::class)
            ->assertSee($this->guard->name)
            ->call('editCell', $this->guard->id, '2026-10-14')
            ->set('editShiftId', (string) $this->nightDuty->id)
            ->set('editPost', 'Gate 2')
            ->call('saveCell')
            ->assertHasNoErrors();

        expect(DutyAssignment::query()->sole())->shift_id->toBe($this->nightDuty->id)->post->toBe('Gate 2');

        $this->travelTo(CarbonImmutable::parse('2026-10-12 06:00'));
        duty($this->guard, '2026-10-12', $this->dayDuty);
        punchAt($this->guard, PunchType::ClockIn);

        Livewire::actingAs($this->ops)->test(DutyRoster::class)
            ->call('editCell', $this->guard->id, '2026-10-12')->set('editShiftId', (string) $this->nightDuty->id)->call('saveCell')
            ->assertHasErrors('editShiftId');
    });

    it('lets Operations type a custom time, starting from the picked duty, and reuses a shift with the same hours', function () {
        $page = Livewire::actingAs($this->ops)->test(DutyRoster::class)
            ->call('editCell', $this->guard->id, '2026-10-14')
            ->set('editShiftId', (string) $this->dayDuty->id)
            ->set('editShiftId', DutyRoster::CUSTOM_SHIFT)
            ->assertSet('editCustomStart', '06:00')->assertSet('editCustomEnd', '15:00')->assertSet('editCustomGrace', '10')
            ->set('editCustomStart', '07:30')->set('editCustomEnd', '07:30')
            ->call('saveCell')
            ->assertHasErrors('editCustomEnd');

        $page->set('editCustomEnd', '16:30')->call('saveCell')->assertHasNoErrors();

        $shift = DutyAssignment::query()->sole()->shift;
        expect($shift)->name->toBe('Custom')->start_time->toStartWith('07:30')->end_time->toStartWith('16:30');

        // The same hours again: the same shift, not a second "Custom".
        Livewire::actingAs($this->ops)->test(DutyRoster::class)
            ->call('editCell', $this->guard->id, '2026-10-15')
            ->set('editShiftId', DutyRoster::CUSTOM_SHIFT)
            ->set('editCustomStart', '07:30')->set('editCustomEnd', '16:30')->set('editCustomGrace', '10')
            ->call('saveCell')->assertHasNoErrors();

        expect(Shift::query()->where('name', 'Custom')->count())->toBe(1);
    });

    it('fills the empty days with usual shifts, Sunday off, without touching set days', function () {
        $this->guard->employeeProfile?->forceFill(['shift_id' => $this->dayDuty->id])->save();
        duty($this->guard, '2026-10-14', $this->nightDuty);

        Livewire::actingAs($this->ops)->test(DutyRoster::class)->call('fillWeek', 'usual');

        $duties = DutyAssignment::query()->where('user_id', $this->guard->id)->orderBy('duty_date')->get();
        // Mon 12 – Sat 17: six days, Wednesday kept as Night Duty, Sunday 18 off.
        expect($duties)->toHaveCount(6)
            ->and($duties->firstWhere(fn ($duty) => $duty->duty_date->toDateString() === '2026-10-14')?->shift_id)->toBe($this->nightDuty->id)
            ->and($duties->contains(fn ($duty) => $duty->duty_date->isSunday()))->toBeFalse();
    });

    it('makes a duty roving, telling Operations whether the client is set up for patrols', function () {
        $page = Livewire::actingAs($this->ops)->test(DutyRoster::class)
            ->call('editCell', $this->guard->id, '2026-10-14')
            ->assertSee('Stays at the post')
            ->assertSee('Makati CBD has no checkpoints yet');

        foreach (['Main gate', 'Parking ramp'] as $name) {
            $checkpoint = new PatrolCheckpoint;
            $checkpoint->forceFill(['client_id' => $this->site->id, 'name' => $name, 'code' => Str::random(32)])->save();
        }

        $page->set('editShiftId', (string) $this->dayDuty->id)->set('editClientId', (string) $this->site->id)
            ->assertSee('2 checkpoints')
            ->assertSee('a full round every 2 hours')
            ->set('editPatrols', true)
            ->call('saveCell')
            ->assertHasNoErrors();

        expect(DutyAssignment::query()->sole()->patrols)->toBeTrue();
    });

    it('keeps a Satellite Operation to its region', function () {
        $satellite = staff(Role::SatelliteOperations);
        $satellite->forceFill(['operations_region' => Region::Calabarzon])->save();

        Livewire::actingAs($satellite)->test(DutyRoster::class)
            ->assertDontSee($this->guard->name)
            ->call('editCell', $this->guard->id, '2026-10-14')
            ->assertNotFound();
    });
});

describe('manual attendance', function () {
    it('records a missed punch by hand, with a reason, marked manual', function () {
        $duty = duty($this->guard, '2026-10-11', $this->dayDuty);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00'));

        expect(fn () => app(RecordManualAttendance::class)->handle($duty, CarbonImmutable::parse('2026-10-11 06:00'), CarbonImmutable::parse('2026-10-11 15:00'), 'phone', $this->ops))
            ->toThrow(ValidationException::class);

        $log = app(RecordManualAttendance::class)->handle($duty, CarbonImmutable::parse('2026-10-11 06:05'), CarbonImmutable::parse('2026-10-11 15:00'), 'Phone broken; detachment commander confirmed.', $this->ops);

        expect($log->isManual())->toBeTrue()
            ->and($log->recorded_by)->toBe($this->ops->id)
            ->and($log->duty_assignment_id)->toBe($duty->id)
            ->and($log->late_minutes)->toBe(0);
    });

    it('never overwrites a selfie punch', function () {
        $duty = duty($this->guard, '2026-10-12', $this->dayDuty);
        punchAt($this->guard, PunchType::ClockIn);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 16:00'));

        expect(fn () => app(RecordManualAttendance::class)->handle($duty, CarbonImmutable::parse('2026-10-12 06:00'), CarbonImmutable::parse('2026-10-12 15:00'), 'Trying to change a real punch', $this->ops))
            ->toThrow(ValidationException::class, 'punched in with a selfie');
    });
});
