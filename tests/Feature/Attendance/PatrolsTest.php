<?php

use App\Actions\Attendance\RecordPunch;
use App\Actions\Patrols\ApproveAway;
use App\Actions\Patrols\ExtendAway;
use App\Actions\Patrols\LeavePost;
use App\Actions\Patrols\RecordPatrolScan;
use App\Actions\Patrols\ReturnToPost;
use App\Actions\Patrols\SendPatrolAlerts;
use App\Actions\Patrols\UpdateAwayLocation;
use App\Enums\AwayReason;
use App\Enums\PunchType;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\AwayMonitor;
use App\Livewire\Admin\Clients;
use App\Livewire\Admin\Patrols;
use App\Models\AwayLog;
use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\PatrolCheckpoint;
use App\Models\PatrolScan;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\PatrolAlert;
use App\Queries\Attendance\PatrolRoundsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Makati CBD and two checkpoints a few dozen metres apart.
const SITE_LAT = 14.5547;
const SITE_LNG = 121.0244;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 06:00'));
    $this->shift = Shift::query()->create(['name' => 'Day Duty', 'start_time' => '06:00', 'end_time' => '15:00', 'grace_minutes' => 10]);
    $this->site = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD', 'latitude' => SITE_LAT, 'longitude' => SITE_LNG, 'geofence_meters' => 150, 'patrol_interval_minutes' => 120]);
    $this->gate = checkpointAt($this->site, 'Main gate', SITE_LAT + 0.0003, SITE_LNG);
    $this->parking = checkpointAt($this->site, 'Parking B1', SITE_LAT - 0.0003, SITE_LNG);
    $this->rover = employee(['client_id' => $this->site->id, 'detachment' => 'Makati CBD', 'post' => 'Roving']);

    $duty = new DutyAssignment;
    $duty->forceFill(['user_id' => $this->rover->id, 'duty_date' => '2026-10-12', 'shift_id' => $this->shift->id, 'client_id' => $this->site->id, 'post' => 'Roving', 'patrols' => true])->save();

    app(RecordPunch::class)->handle($this->rover, PunchType::ClockIn, cameraPhoto(), SITE_LAT, SITE_LNG, 10);
});

function checkpointAt(Client $site, string $name, ?float $lat, ?float $lng): PatrolCheckpoint
{
    $checkpoint = new PatrolCheckpoint;
    $checkpoint->forceFill(['client_id' => $site->id, 'name' => $name, 'code' => Str::random(32), 'latitude' => $lat, 'longitude' => $lng])->save();

    return $checkpoint;
}

function scan(User $guard, PatrolCheckpoint $checkpoint, ?float $lat = null, ?float $lng = null, ?int $accuracy = 8): PatrolScan
{
    return app(RecordPatrolScan::class)->handle($guard, $checkpoint->qrPayload(), cameraPhoto(), $lat ?? (float) $checkpoint->latitude, $lng ?? (float) $checkpoint->longitude, $accuracy);
}

describe('checkpoint scans', function () {
    it('records a scan with time, GPS and the camera frame', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 06:40'));

        $scan = scan($this->rover, $this->gate);

        expect($scan->checkpoint->name)->toBe('Main gate')
            ->and($scan->photo_path)->toStartWith("patrols/{$this->rover->id}/2026-10-12/scan-")
            ->and($scan->distance_meters)->toBeLessThan(5);
    });

    it('refuses a made-up code, another client\'s sticker, a scan from far away, and anyone not clocked in', function () {
        expect(fn () => app(RecordPatrolScan::class)->handle($this->rover, 'OSSI-CP:not-a-real-code', cameraPhoto(), SITE_LAT, SITE_LNG, 8))
            ->toThrow(ValidationException::class, 'isn\'t an OSSI checkpoint');

        $other = Client::query()->create(['region' => Region::Ncr, 'province' => 'Taguig', 'name' => 'BGC Tower One']);
        expect(fn () => scan($this->rover, checkpointAt($other, 'BGC lobby', null, null), SITE_LAT, SITE_LNG))
            ->toThrow(ValidationException::class, 'BGC lobby is at BGC Tower One. Your post today is');

        // A photo of the sticker, scanned from 1 km away.
        expect(fn () => scan($this->rover, $this->gate, SITE_LAT + 0.009, SITE_LNG))
            ->toThrow(ValidationException::class, 'from Main gate');

        expect(fn () => scan(employee(['client_id' => $this->site->id]), $this->gate))
            ->toThrow(ValidationException::class, 'Clock in first');
    });

    it('treats the same checkpoint again within minutes as the same scan', function () {
        $first = scan($this->rover, $this->gate);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 06:03'));

        expect(scan($this->rover, $this->gate)->id)->toBe($first->id)
            ->and(PatrolScan::query()->count())->toBe(1);
    });
});

describe('rounds', function () {
    it('marks a round done when every checkpoint was scanned in its window, missed otherwise', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 06:30'));
        scan($this->rover, $this->gate);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 07:10'));
        scan($this->rover, $this->parking);
        // Round 2 (08:00–10:00): only the gate.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 08:30'));
        scan($this->rover, $this->gate);

        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:05'));
        $log = $this->rover->timeLogs()->sole();
        $rounds = app(PatrolRoundsQuery::class)->handle($log, CarbonImmutable::now())['rounds'];

        expect($rounds[0]['state'])->toBe('done')
            ->and($rounds[1]['state'])->toBe('missed')
            ->and($rounds[1]['missing'])->toBe(['Parking B1'])
            ->and($rounds[2]['state'])->toBe('current');
    });

    it('alerts Operations once about a missed round', function () {
        Notification::fake();
        $hq = staff(Role::OperationsHq);
        $otherRegion = staff(Role::SatelliteOperations);
        $otherRegion->forceFill(['operations_region' => Region::CentralVisayas])->save();

        $this->travelTo(CarbonImmutable::parse('2026-10-12 08:05'));

        expect(app(SendPatrolAlerts::class)->handle(CarbonImmutable::now()))->toBe(1)
            ->and(app(SendPatrolAlerts::class)->handle(CarbonImmutable::now()))->toBe(0);

        Notification::assertSentTo($hq, PatrolAlert::class, fn (PatrolAlert $alert) => str_contains($alert->headline, 'Round 1 missed'));
        Notification::assertNotSentTo($otherRegion, PatrolAlert::class);
    });
});

describe('away from post', function () {
    it('logs leaving and coming back, recording where "Back at post" was tapped', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));

        expect(fn () => app(LeavePost::class)->handle($this->rover, AwayReason::Appointment, '', SITE_LAT, SITE_LNG))
            ->toThrow(ValidationException::class, 'Say where you\'re going');

        $away = app(LeavePost::class)->handle($this->rover, AwayReason::Appointment, 'Dentist on Pasong Tamo', SITE_LAT, SITE_LNG);

        expect(fn () => app(LeavePost::class)->handle($this->rover, AwayReason::Patrol, null, SITE_LAT, SITE_LNG))->toThrow(ValidationException::class, 'already marked away');

        // Tapped ~2.5 km away: not blocked, but the distance is kept for Operations.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:25'));
        app(ReturnToPost::class)->handle($this->rover, 14.5530, 121.0010, 10);

        expect($away->fresh())
            ->returned_at->not->toBeNull()
            ->returned_distance_meters->toBeGreaterThan(2000)
            ->and($away->fresh()?->minutesAway(CarbonImmutable::now()))->toBe(25);
    });

    it('reminds the guard to share their location, and alerts Operations only after a long silence', function () {
        Notification::fake();
        $hq = staff(Role::OperationsHq);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
        $away = app(LeavePost::class)->handle($this->rover, AwayReason::ClientErrand, 'Bank deposit for the admin office', SITE_LAT, SITE_LNG);

        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:10'));
        $update = app(UpdateAwayLocation::class)->handle($this->rover, 14.5560, 121.0230, 12);
        expect($update->distance_meters)->toBeGreaterThan(100)->toBeLessThan(300)
            ->and($away->fresh()?->isLocationDue(CarbonImmutable::now()))->toBeFalse();

        $locationAlerts = fn () => Notification::sent($hq, PatrolAlert::class)->filter(fn (PatrolAlert $alert) => str_contains($alert->headline, 'No location shared'));

        // 16 minutes on: a reminder on their phone, nothing to Operations.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:26'));
        expect($away->fresh()?->isLocationDue(CarbonImmutable::now()))->toBeTrue()
            ->and($away->fresh()?->isLocationOverdue(CarbonImmutable::now()))->toBeFalse();
        app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
        expect($locationAlerts())->toHaveCount(0);

        // 31 minutes of silence: one alert (sent once). The rover's missed rounds alert separately.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:41'));
        app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
        app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
        expect($locationAlerts())->toHaveCount(1)
            ->and($locationAlerts()->first()?->headline)->toContain('No location shared for 31 min')
            ->and($locationAlerts()->first()?->detail)->toContain('expected back by 11:00 AM');

        // Sharing again clears it.
        app(UpdateAwayLocation::class)->handle($this->rover, SITE_LAT, SITE_LNG, 10);
        expect($away->fresh()?->isLocationDue(CarbonImmutable::now()))->toBeFalse();
    });

    it('measures time away against the guard\'s own expected return: late is amber, an alert only well past it', function () {
        Notification::fake();
        $hq = staff(Role::OperationsHq);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
        // An errand for the client is usually an hour: back by 11:00.
        $away = app(LeavePost::class)->handle($this->rover, AwayReason::ClientErrand, 'Bank deposit for the admin office', SITE_LAT, SITE_LNG);
        expect($away->expectedBackAt()->format('H:i'))->toBe('11:00');

        $lateAlerts = fn () => Notification::sent($hq, PatrolAlert::class)->filter(fn (PatrolAlert $alert) => str_contains($alert->headline, 'Not back yet'));

        // 30 min past it (still sharing): flagged, not alerted, not "needs attention".
        $this->travelTo(CarbonImmutable::parse('2026-10-12 11:30'));
        app(UpdateAwayLocation::class)->handle($this->rover, 14.5600, 121.0300, 15);
        expect($away->fresh()?->isPastExpected(CarbonImmutable::now()))->toBeTrue()
            ->and($away->fresh()?->isLate(CarbonImmutable::now()))->toBeFalse()
            ->and($away->fresh()?->needsAttention(CarbonImmutable::now()))->toBeFalse();
        app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
        expect($lateAlerts())->toHaveCount(0);

        // An hour past it, with no time added: one alert.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00'));
        app(UpdateAwayLocation::class)->handle($this->rover, 14.5600, 121.0300, 15);
        app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
        app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
        expect($lateAlerts())->toHaveCount(1)
            ->and($lateAlerts()->first()?->headline)->toContain('60 min past 11:00 AM');

        expect(fn () => app(ApproveAway::class)->handle($away, $this->rover))->toThrow(AuthorizationException::class);
        app(ApproveAway::class)->handle($away, $hq);
        expect($away->fresh()?->isPastExpected(CarbonImmutable::now()))->toBeFalse()
            ->and($away->fresh()?->isLate(CarbonImmutable::now()))->toBeFalse();
    });

    it('lets the guard pick how long, and add time with a reason, without approval', function () {
        Notification::fake();
        $hq = staff(Role::OperationsHq);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));

        expect(fn () => app(LeavePost::class)->handle($this->rover, AwayReason::Appointment, 'Dentist on Pasong Tamo', SITE_LAT, SITE_LNG, 45))
            ->toThrow(ValidationException::class, 'Choose one of the lengths listed');
        $away = app(LeavePost::class)->handle($this->rover, AwayReason::Appointment, 'Dentist on Pasong Tamo', SITE_LAT, SITE_LNG, 30);
        expect($away->expectedBackAt()->format('H:i'))->toBe('10:30');

        expect(fn () => app(ExtendAway::class)->handle($this->rover, 45, 'Traffic'))->toThrow(ValidationException::class, 'Choose how much time')
            ->and(fn () => app(ExtendAway::class)->handle($this->rover, 30, ' '))->toThrow(ValidationException::class, 'Say why');

        // Before the time: added to it. After it: added from now.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:20'));
        expect(app(ExtendAway::class)->handle($this->rover, 30, 'Long queue at the clinic')->new_back_at->format('H:i'))->toBe('11:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 11:10'));
        expect(app(ExtendAway::class)->handle($this->rover, 15, 'Traffic on EDSA')->new_back_at->format('H:i'))->toBe('11:25')
            ->and($away->fresh()?->isPastExpected(CarbonImmutable::now()))->toBeFalse();

        // Late again on the new time: the lateness alert is per expected time, so it comes again.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:25'));
        app(UpdateAwayLocation::class)->handle($this->rover, 14.5600, 121.0300, 15);
        app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
        Notification::assertSentTo($hq, PatrolAlert::class, fn (PatrolAlert $alert) => str_contains($alert->headline, 'Not back yet: 60 min past 11:25 AM'));

        // Nobody plans or stretches one absence past the day's cap.
        expect(fn () => app(ExtendAway::class)->handle($this->rover, 120, 'Still at the clinic'))->not->toThrow(ValidationException::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 16:30'));
        expect(fn () => app(ExtendAway::class)->handle($this->rover, 120, 'Still at the clinic'))->toThrow(ValidationException::class, 'longer than one absence');

        // The supervisor sees why on the timeline.
        Livewire::actingAs($hq)->test(AwayMonitor::class, ['scope' => 'field'])
            ->call('viewAway', $away->id)
            ->assertSee('Needed more time')
            ->assertSee('Traffic on EDSA')
            ->assertSee('said back by 10:30 AM');
    });

    it('ends an absence at clock-out', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 14:40'));
        app(LeavePost::class)->handle($this->rover, AwayReason::Patrol, null, SITE_LAT, SITE_LNG);

        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00'));
        app(RecordPunch::class)->handle($this->rover, PunchType::ClockOut, cameraPhoto(), SITE_LAT, SITE_LNG, 10);

        expect(AwayLog::query()->open()->count())->toBe(0);
    });

    it('shows the absence on the client\'s Today view', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
        app(LeavePost::class)->handle($this->rover, AwayReason::Appointment, 'Dentist on Pasong Tamo', SITE_LAT, SITE_LNG);

        Livewire::actingAs(staff(Role::SuperAdmin))->test(Clients::class)
            ->call('viewGuards', $this->site->id)
            ->assertSee('Away · Appointment')
            ->assertSee('Dentist on Pasong Tamo');
    });
});

describe('the Patrols page', function () {
    it('lets Operations add a checkpoint and print its QR', function () {
        $hq = staff(Role::OperationsHq);

        Livewire::actingAs($hq)->test(Patrols::class)
            ->set('tab', 'checkpoints')
            ->set('clientId', (string) $this->site->id)
            ->call('createCheckpoint')
            ->set('checkpointName', 'Roof deck door')
            ->call('saveCheckpoint')
            ->assertHasNoErrors()
            ->assertSee('Roof deck door');

        $this->actingAs($hq)->get(route('patrols.print', $this->site))->assertOk()->assertSee('Roof deck door')->assertSee('<svg', false);
    });

    it('opens a location window with the address and the whole trail', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
        $away = app(LeavePost::class)->handle($this->rover, AwayReason::ClientErrand, 'Bank deposit for the admin office', SITE_LAT, SITE_LNG);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:12'));
        app(UpdateAwayLocation::class)->handle($this->rover, 14.5600, 121.0300, 15);

        Livewire::actingAs(staff(Role::OperationsHq))->test(AwayMonitor::class, ['scope' => 'field'])
            ->call('viewAway', $away->id)
            ->assertSee('Last seen')
            ->assertSee('Ortigas Center, Pasig City') // the (faked) street address of the latest spot
            ->assertSee('Left the post')
            ->assertSee('Shared location')
            ->assertSee('Open in Google Maps')
            ->assertSee('Precise location') // ±15 m, in words
            ->assertSee('Approve time away');

        $satellite = staff(Role::SatelliteOperations);
        $satellite->forceFill(['operations_region' => Region::Calabarzon])->save();
        Livewire::actingAs($satellite)->test(AwayMonitor::class, ['scope' => 'field'])->call('viewAway', $away->id)->assertNotFound();
    });

    it('shows who is away and roving, within a Satellite\'s region only', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
        app(LeavePost::class)->handle($this->rover, AwayReason::Patrol, 'Checking Gate 2', SITE_LAT, SITE_LNG);

        Livewire::actingAs(staff(Role::OperationsHq))->test(AwayMonitor::class, ['scope' => 'field'])->assertSee($this->rover->name)->assertSee('Checking other posts');

        $satellite = staff(Role::SatelliteOperations);
        $satellite->forceFill(['operations_region' => Region::Calabarzon])->save();
        Livewire::actingAs($satellite)->test(AwayMonitor::class, ['scope' => 'field'])->assertDontSee($this->rover->name);
        $this->actingAs($satellite)->get(route('patrols.print', $this->site))->assertForbidden();
    });

    it('filters guards away by the region of their post, for viewers who see every region', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
        app(LeavePost::class)->handle($this->rover, AwayReason::Patrol, 'Checking Gate 2', SITE_LAT, SITE_LNG);

        Livewire::actingAs(staff(Role::OperationsHq))->test(AwayMonitor::class, ['scope' => 'field'])
            ->assertSee('All regions')
            ->set('region', Region::Calabarzon->value)->assertDontSee($this->rover->name)->assertSee('Nobody out matches these filters.')
            ->set('region', Region::Ncr->value)->assertSee($this->rover->name);

        // A Satellite already sees one region: no region filter.
        $satellite = staff(Role::SatelliteOperations);
        $satellite->forceFill(['operations_region' => Region::Ncr])->save();
        Livewire::actingAs($satellite)->test(AwayMonitor::class, ['scope' => 'field'])->assertDontSee('All regions')->assertSee($this->rover->name);
    });
});
