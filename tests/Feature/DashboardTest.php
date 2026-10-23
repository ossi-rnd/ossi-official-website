<?php

use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\AttendanceDashboard;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\TimeLog;
use App\Models\User;
use App\Queries\Attendance\TodayRosterQuery;
use App\Queries\Dashboard\DashboardHighlightsQuery;
use App\Queries\People\WorkforceByAreaQuery;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// One dashboard for every staff role; each section has its own Ability, so
// each role sees its part of the job.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));

    $this->imus = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $this->calamba = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Laguna', 'name' => 'Metrobank – Calamba']);
    $this->megamall = Client::query()->create(['region' => Region::Ncr, 'province' => 'Mandaluyong', 'name' => 'SM Megamall']);

    $guard = function (string $name, Client $client, ?string $status = null) {
        $user = employee(['client_id' => $client->id, 'detachment' => $client->name, 'name' => $name]);
        $user->forceFill(['name' => $name])->save();

        if ($status !== null) {
            TimeLog::factory()->for($user)->on(CarbonImmutable::today())->create(['status' => $status, 'clock_out_at' => null]);
        }

        return $user;
    };

    $guard('Ivan Imus', $this->imus, 'on_time');
    $guard('Isa Imus', $this->imus, 'late');
    $guard('Carla Calamba', $this->calamba);
    $guard('Mega Mario', $this->megamall, 'on_time');
});

it('sends HR and Accounting to the dashboard now, and guards to their attendance', function () {
    $this->actingAs(staff(Role::Hr))->get(route('home'))->assertRedirect(route('dashboard'));
    $this->actingAs(staff(Role::Accounting))->get(route('home'))->assertRedirect(route('dashboard'));
    $this->actingAs(employee())->get(route('home'))->assertRedirect(route('attendance'));
});

it('counts every employee for the Executive, and says how many clock in (the Headcount) and how many have no account', function () {
    $executive = staff(Role::President);
    EmployeeProfile::factory()->create(['user_id' => null]);

    $tile = collect(app(DashboardHighlightsQuery::class)->handle($executive, CarbonImmutable::today()))->firstWhere('label', 'Employees');

    // 4 guards + 1 without an account. The President has no HR record, so isn't counted.
    expect($tile)->toMatchArray(['value' => '5', 'hint' => '4 clock in · 1 no account yet']);
});

it('counts people and today\'s attendance by region, province and client', function () {
    $workforce = app(WorkforceByAreaQuery::class)->handle(app(TodayRosterQuery::class)->handle());

    // NCR comes before Region IV-A (the enum's order); Cavite before Laguna.
    expect(collect($workforce['regions'])->pluck('label')->all())->toBe(['NCR', 'Region IV-A']);

    $calabarzon = $workforce['regions'][1];
    expect($calabarzon['counts'])->toBe(['people' => 3, 'in' => 2, 'late' => 1, 'notIn' => 1, 'onLeave' => 0])
        ->and(collect($calabarzon['provinces'])->pluck('name')->all())->toBe(['Cavite', 'Laguna'])
        ->and($calabarzon['provinces'][0]['clients'][0])->toMatchArray(['name' => 'Metrobank – Imus', 'counts' => ['people' => 2, 'in' => 2, 'late' => 1, 'notIn' => 0, 'onLeave' => 0]]);

    expect($workforce['total']['people'])->toBe(4)->and($workforce['office']['people'])->toBe(0);
});

it('shows HR what waits for them, their people numbers, the areas and today', function () {
    $hr = staff(Role::Hr);
    // A guard's leave at HR's step: a "Waiting for you" card saying what's needed.
    LeaveRequest::query()->forceCreate(['user_id' => User::query()->where('name', 'Carla Calamba')->value('id'), 'type' => 'vacation', 'start_date' => '2026-10-20', 'end_date' => '2026-10-21', 'reason' => 'Family', 'status' => 'pending', 'stage' => 'hr']);

    Livewire::actingAs($hr)->test(AttendanceDashboard::class)
        ->assertSee('Good morning')
        ->assertSee('Waiting for you')->assertSee('Leave Requests')->assertSee('to decide')
        ->assertSee('Probation ending')->assertSee('No account yet')
        ->assertSee('People by area')->assertSee('Region IV-A')->assertSee('Metrobank – Imus')
        ->assertSee("Today's DTR")
        ->assertDontSee('Latest payroll run')->assertDontSee('Relievers sent');
});

it('shows Accounting the payroll run, headcount by area, and today\'s attendance', function () {
    PayrollRun::query()->forceCreate(['start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'pay_date' => '2026-10-20', 'status' => 'processing']);

    Livewire::actingAs(staff(Role::Accounting))->test(AttendanceDashboard::class)
        ->assertSee('Latest payroll run')->assertSee('Processing')
        // Pay goes out in 5 days (Oct 15 → Oct 20).
        ->assertSee('Next pay day')->assertSee('Oct 20')->assertSee('in 5 days')
        // The run to finish, with the guards who worked and have no payslip yet.
        ->assertSee('Runs to finish')->assertSee('October 1–15, 2026')->assertSee('0 payslips')
        ->assertSee('People by area')->assertSee('Metrobank – Imus')
        // Attendance too (2026-10-23): payroll comes from it.
        ->assertSee('Not in yet')
        ->assertDontSee('Probation ending');
});

it('narrows today\'s numbers and the DTR to the chosen area', function () {
    $page = Livewire::actingAs(staff(Role::OperationsHq))->test(AttendanceDashboard::class)
        ->assertSee('Relievers sent')->assertSee('Absences reported')
        ->set('areaRegion', Region::Calabarzon->value)->set('areaProvince', 'Cavite');

    expect($page->instance()->stats['total'])->toBe(2)
        ->and(collect($page->instance()->filtered)->pluck('name')->all())->toBe(['Isa Imus', 'Ivan Imus'])
        // The numbers say what they cover: the province picked, or the whole company.
        ->and($page->instance()->scopeLabel())->toBe('Cavite');

    // Operation HQ counts guards only (see below).
    $page->call('clearArea');
    expect($page->instance()->scopeLabel())->toBe('all guards');
});

it('gives a Satellite Operation every guard like Operation HQ, but opens records in its region only', function () {
    $satellite = staff(Role::SatelliteOperations);
    $satellite->forceFill(['operations_region' => Region::Ncr])->save();
    $mario = User::query()->where('name', 'Mega Mario')->firstOrFail()->employeeProfile()->firstOrFail();
    $ivan = User::query()->where('name', 'Ivan Imus')->firstOrFail()->employeeProfile()->firstOrFail();

    $page = Livewire::actingAs($satellite)->test(AttendanceDashboard::class)
        ->assertSee('SM Megamall')->assertSee('Metrobank – Imus')
        // Its own region's guard is a link; another region's is plain text (the record would refuse them).
        ->assertSee(route('employees.show', $mario), false)
        ->assertDontSee(route('employees.show', $ivan), false);

    expect($page->instance()->stats['total'])->toBe(4)
        ->and(collect($page->instance()->workforce['regions'])->pluck('label')->all())->toBe(['NCR', 'Region IV-A'])
        ->and($page->instance()->areaRegions())->toBe([Region::Ncr, Region::Calabarzon])
        ->and($page->instance()->scopeLabel())->toBe('all guards');

    // Elsewhere it stays limited to its region.
    $this->actingAs($satellite)->get(route('employees.show', $ivan))->assertForbidden();
});

it('charts the daily attendance rate over 7, 14 or 30 days, with today against yesterday', function () {
    // Three of the four guards clocked in today (beforeEach); one yesterday.
    $ivan = User::query()->where('name', 'Ivan Imus')->firstOrFail();
    TimeLog::factory()->for($ivan)->on(CarbonImmutable::yesterday())->create();

    $page = Livewire::actingAs(staff(Role::OperationsHq))->test(AttendanceDashboard::class);

    $trend = $page->instance()->trend;
    expect($trend)->toHaveCount(7)
        ->and($trend[6])->toMatchArray(['date' => '2026-10-15', 'in' => 3, 'expected' => 4, 'rate' => 75])
        ->and($page->instance()->trendHeadline)->toBe(['rate' => 75, 'in' => 3, 'expected' => 4, 'change' => 50]);

    $page->call('setTrendDays', 30);
    expect($page->instance()->trend)->toHaveCount(30)->and($page->instance()->trend[0]['day'])->toBe('Sep 16');

    // Anything but 7 / 14 / 30 falls back to a week.
    $page->call('setTrendDays', 365);
    expect($page->instance()->trendDays)->toBe(7);
});

it('counts only guards for Operations, and everyone for HR', function () {
    $clerk = staff(Role::Accounting);
    $clerk->forceFill(['name' => 'Olivia Office'])->save();

    // Operation HQ: the four guards, all regions; no office staff, not even themselves.
    $ops = Livewire::actingAs(staff(Role::OperationsHq))->test(AttendanceDashboard::class)
        ->assertDontSee('Office staff (no client)');
    expect($ops->instance()->stats['total'])->toBe(4)
        ->and(collect($ops->instance()->roster)->pluck('name'))->not->toContain('Olivia Office')
        ->and($ops->instance()->workforce['office']['people'])->toBe(0)
        ->and($ops->instance()->scopeLabel())->toBe('all guards');

    // HR: the whole company, office staff included.
    $hr = Livewire::actingAs(staff(Role::Hr))->test(AttendanceDashboard::class)
        ->assertSee('Office staff (no client)');
    expect(collect($hr->instance()->roster)->pluck('name'))->toContain('Olivia Office')
        ->and($hr->instance()->scopeLabel())->toBe('whole company');
});
