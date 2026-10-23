<?php

use App\Actions\Attendance\RecordPunch;
use App\Actions\Patrols\ApproveAway;
use App\Actions\Patrols\LeavePost;
use App\Actions\Patrols\ReturnToPost;
use App\Actions\Patrols\SendPatrolAlerts;
use App\Actions\Patrols\UpdateAwayLocation;
use App\Enums\AwayReason;
use App\Enums\PunchType;
use App\Enums\Role;
use App\Livewire\Admin\AwayMonitor;
use App\Livewire\Admin\Patrols;
use App\Livewire\Attendance\ClockPanel;
use App\Models\AwayLog;
use App\Notifications\PatrolAlert;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// The head office (Ortigas), set the way .env would.
const OFFICE_LAT = 14.5869;
const OFFICE_LNG = 121.0614;

beforeEach(function () {
    config(['attendance.office' => ['name' => 'OSSI Head Office', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'radius_meters' => 150]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:05'));
    $this->accountant = staff(Role::Accounting);
    $this->accountant->forceFill(['name' => 'Patricia Accountant'])->save();
    app(RecordPunch::class)->handle($this->accountant, PunchType::ClockIn, cameraPhoto(), OFFICE_LAT, OFFICE_LNG, 10);
    $this->executive = staff(Role::President);
    $this->opsHq = staff(Role::OperationsHq);
    // The accountant's head (App\Support\Heads): HR.
    $this->hr = staff(Role::Hr);
});

it('gives office staff office reasons, not guard ones', function () {
    expect(fn () => app(LeavePost::class)->handle($this->accountant, AwayReason::Patrol, null, OFFICE_LAT, OFFICE_LNG))
        ->toThrow(ValidationException::class, 'Choose one of the reasons listed')
        ->and(fn () => app(LeavePost::class)->handle($this->accountant, AwayReason::OfficialBusiness, '', OFFICE_LAT, OFFICE_LNG))
        ->toThrow(ValidationException::class, 'Say where you\'re going');

    $away = app(LeavePost::class)->handle($this->accountant, AwayReason::OfficialBusiness, 'BDO Ortigas, depositing payroll', OFFICE_LAT, OFFICE_LNG);

    // Measured from the head office.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:20'));
    expect(app(UpdateAwayLocation::class)->handle($this->accountant, 14.5880, 121.0570, 10)->distance_meters)->toBeGreaterThan(400)->toBeLessThan(600)
        ->and($away->fresh()?->reason)->toBe(AwayReason::OfficialBusiness);
});

it('has a government-office reason (3 hours) and gives appointments 2 hours, each needing a note', function () {
    expect(AwayReason::forOffice())->toContain(AwayReason::GovernmentOffice)
        ->and(AwayReason::forField())->not->toContain(AwayReason::GovernmentOffice)
        ->and(fn () => app(LeavePost::class)->handle($this->accountant, AwayReason::GovernmentOffice, '', OFFICE_LAT, OFFICE_LNG))
        ->toThrow(ValidationException::class, 'Say where you\'re going');

    $away = app(LeavePost::class)->handle($this->accountant, AwayReason::GovernmentOffice, 'SSS Pasig, remitting contributions', OFFICE_LAT, OFFICE_LNG);
    expect($away->expectedBackAt()->format('H:i'))->toBe('12:05')
        ->and(AwayReason::Appointment->usualMinutes())->toBe(120);
});

it('suggests no time: they choose their own and are held to it, and only personal reasons point to leave', function () {
    $panel = Livewire::actingAs($this->accountant)->test(ClockPanel::class)
        ->assertSee('Choose your time')
        ->assertDontSee('usually')
        ->assertDontSee('Usual:')
        ->assertDontSee('File it as leave')
        ->set('awayReason', AwayReason::GovernmentOffice->value)
        ->assertSee('e.g. SSS Pasig, remitting contributions')
        ->assertDontSee('File it as leave')
        ->set('awayReason', AwayReason::Personal->value)
        ->assertSee('Out for more than 4 hrs? File it as leave on');

    // The note asks what's useful for each reason: what for on work trips, only where on personal ones
    // (an appointment's purpose is health information, RA 10173).
    $panel->set('awayReason', AwayReason::OfficialBusiness->value)->assertSee('Where, and what for')->assertDontSee('who asked')
        ->set('awayReason', AwayReason::Appointment->value)->assertSee('Where (no need to say what for)')->assertSee('e.g. Robinsons Galleria')->assertDontSee('Dentist');

    // No time picked: refused, nothing recorded.
    expect($panel->instance()->leavePost(app(LeavePost::class), OFFICE_LAT, OFFICE_LNG))
        ->toMatchArray(['ok' => false, 'message' => 'Choose how long you\'ll be out. Coming back early is fine.']);

    // A 20-minute errand said as 30 minutes: back by 09:35, not the 2 hours Official business used to suggest.
    $panel->set('awayReason', AwayReason::OfficialBusiness->value)->set('awayMinutes', '30')->set('awayNote', 'BDO Ortigas, depositing payroll');
    expect($panel->instance()->leavePost(app(LeavePost::class), OFFICE_LAT, OFFICE_LNG))->toMatchArray(['ok' => true]);
    expect(AwayLog::query()->sole()->expectedBackAt()->format('H:i'))->toBe('09:35');
});

it('lets office staff log a client site visit (3 hours, with a note), not guards', function () {
    expect(AwayReason::forOffice())->toContain(AwayReason::ClientSiteVisit)
        ->and(AwayReason::forField())->not->toContain(AwayReason::ClientSiteVisit)
        ->and(fn () => app(LeavePost::class)->handle($this->accountant, AwayReason::ClientSiteVisit, '', OFFICE_LAT, OFFICE_LNG))
        ->toThrow(ValidationException::class, 'Say where you\'re going');

    $away = app(LeavePost::class)->handle($this->accountant, AwayReason::ClientSiteVisit, 'Makati CBD, inspecting Gate 2', OFFICE_LAT, OFFICE_LNG);
    expect($away->expectedBackAt()->format('H:i'))->toBe('12:05')
        ->and($away->reason->label())->toBe('Client site visit');
});

it('is approved by their head (HR for an accountant), watched by the President or VP, not Operations', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
    $away = app(LeavePost::class)->handle($this->accountant, AwayReason::OfficialBusiness, 'BIR Pasig, filing 1601-C', OFFICE_LAT, OFFICE_LNG);

    // The President or VP sees everyone's (they monitor) but approves only HR staff's.
    Livewire::actingAs($this->executive)->test(AwayMonitor::class)
        ->assertSee('Office staff out of the office')
        ->assertSee('Patricia Accountant')
        ->call('viewAway', $away->id)
        ->assertSee('OSSI Head Office');
    expect(fn () => app(ApproveAway::class)->handle($away, $this->executive))->toThrow(AuthorizationException::class);

    // Operation HQ's Office Away lists only the Satellites it heads; Patrols, guards only.
    Livewire::actingAs($this->opsHq)->test(AwayMonitor::class, ['scope' => 'field'])->assertDontSee('Patricia Accountant');
    Livewire::actingAs($this->opsHq)->test(AwayMonitor::class)->assertDontSee('Patricia Accountant');
    expect(fn () => app(ApproveAway::class)->handle($away, $this->opsHq))->toThrow(AuthorizationException::class);

    Livewire::actingAs($this->hr)->test(AwayMonitor::class)->assertSee('Patricia Accountant');
    app(ApproveAway::class)->handle($away, $this->hr);
    expect($away->fresh()?->approved_by)->toBe($this->hr->id);
});

it('alerts their head (HR), not Operations, when office staff go silent or are well past their time', function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
    // Official business is usually two hours: back by 12:00, and 40 minutes out is nothing unusual.
    app(LeavePost::class)->handle($this->accountant, AwayReason::OfficialBusiness, 'BIR Pasig, filing 1601-C', OFFICE_LAT, OFFICE_LNG);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:40'));
    app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
    Notification::assertSentTo($this->hr, PatrolAlert::class, fn (PatrolAlert $alert) => str_contains($alert->headline, 'No location shared for 40 min') && $alert->url === route('office-away'));
    Notification::assertNotSentTo($this->hr, PatrolAlert::class, fn (PatrolAlert $alert) => str_contains($alert->headline, 'Not back yet'));

    $this->travelTo(CarbonImmutable::parse('2026-10-12 13:00'));
    app(UpdateAwayLocation::class)->handle($this->accountant, 14.5880, 121.0570, 10);
    app(SendPatrolAlerts::class)->handle(CarbonImmutable::now());
    Notification::assertSentTo($this->hr, PatrolAlert::class, fn (PatrolAlert $alert) => str_contains($alert->headline, 'Not back yet: 60 min past 12:00 PM') && str_contains($alert->detail, 'Away from the office'));
    Notification::assertNotSentTo([$this->opsHq, $this->executive], PatrolAlert::class);
});

it('sorts who is out into status tabs, needing attention first, and filters by reason and name', function () {
    $cashier = staff(Role::Accounting);
    $cashier->forceFill(['name' => 'Carlo Cashier'])->save();
    app(RecordPunch::class)->handle($cashier, PunchType::ClockIn, cameraPhoto(), OFFICE_LAT, OFFICE_LNG, 10);

    // The cashier leaves first, for an hour's appointment, and keeps sharing their location;
    // the accountant leaves later for "30 minutes" of official business, runs over and goes silent.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
    app(LeavePost::class)->handle($cashier, AwayReason::Appointment, 'Dentist at Robinsons Galleria', OFFICE_LAT, OFFICE_LNG);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:05'));
    app(LeavePost::class)->handle($this->accountant, AwayReason::OfficialBusiness, 'BIR Pasig, filing 1601-C', OFFICE_LAT, OFFICE_LNG, 30);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:38'));
    app(UpdateAwayLocation::class)->handle($cashier, 14.5880, 121.0570, 10);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:40'));

    $monitor = Livewire::actingAs($this->executive)->test(AwayMonitor::class);
    expect($monitor->instance()->statusCounts)->toBe(['all' => 2, 'attention' => 1, 'late' => 1, 'overdue' => 1, 'approved' => 0])
        // The one needing attention comes first, though they left later.
        ->and($monitor->instance()->awayNow->pluck('user.name')->all())->toBe(['Patricia Accountant', 'Carlo Cashier']);

    $monitor->set('status', 'attention')->assertSee('Patricia Accountant')->assertDontSee('Carlo Cashier')
        ->set('status', 'approved')->assertSee('Nobody out matches these filters.')
        ->set('status', 'all')->set('reason', AwayReason::Appointment->value)->assertSee('Carlo Cashier')->assertDontSee('Patricia Accountant')
        ->set('reason', '')->set('search', 'patri')->assertSee('Patricia Accountant')->assertDontSee('Carlo Cashier');
});

it('pages the people back today and counts them all', function () {
    foreach (range(1, 16) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00')->addMinutes($minute * 2));
        app(LeavePost::class)->handle($this->accountant, AwayReason::Personal, 'Pharmacy downstairs', OFFICE_LAT, OFFICE_LNG);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:01')->addMinutes($minute * 2));
        app(ReturnToPost::class)->handle($this->accountant, OFFICE_LAT, OFFICE_LNG, 10);
    }

    $monitor = Livewire::actingAs($this->executive)->test(AwayMonitor::class)->assertSee('Back in the office today · 16');
    expect($monitor->instance()->awayToday->count())->toBe(15)
        ->and($monitor->instance()->awayToday->lastPage())->toBe(2);
});

it('links each name to the person\'s employee record', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
    $away = app(LeavePost::class)->handle($this->accountant, AwayReason::OfficialBusiness, 'BIR Pasig, filing 1601-C', OFFICE_LAT, OFFICE_LNG);
    $record = route('employees.show', $this->accountant->employeeProfile()->firstOrFail());

    Livewire::actingAs($this->executive)->test(AwayMonitor::class)
        ->assertSee($record, false)
        ->call('viewAway', $away->id)
        ->assertSee('title="View Patricia Accountant\'s profile"', false); // the name link at the top of the window

    // ...on the other lists too, e.g. today's DTR.
    $this->actingAs($this->executive)->get(route('dashboard'))->assertSee($record, false);
});

it('keeps the Patrols page working for Operations with the guards\' list embedded', function () {
    Livewire::actingAs($this->opsHq)->test(Patrols::class)->assertOk()->assertSeeLivewire(AwayMonitor::class);
});
