<?php

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use App\Notifications\ProbationEnding;
use App\Queries\Dashboard\DashboardHighlightsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:00'));
    $this->hr = staff(Role::Hr);
    $this->accountant = staff(Role::Accounting);
});

it('tells HR 30 days before probation ends, once', function () {
    // Hired Apr 20 → six months on Oct 20, inside the 30-day window.
    $profile = EmployeeProfile::factory()->create(['employment_status' => 'probationary', 'hire_date' => '2026-04-20']);

    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 1 probation alert.')->assertSuccessful();
    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 0 probation alerts.');

    Notification::assertSentToTimes($this->hr, ProbationEnding::class, 1);
    Notification::assertSentTo($this->hr, ProbationEnding::class, fn (ProbationEnding $alert) => $alert->profile->is($profile) && ! $alert->isDue && $alert->endsOn->toDateString() === '2026-10-20');
    Notification::assertNotSentTo($this->accountant, ProbationEnding::class);
    expect(ActivityLog::query()->where('event', 'probation.alert_sent')->count())->toBe(1);
});

it('sends a second alert on the day probation ends', function () {
    EmployeeProfile::factory()->create(['employment_status' => 'probationary', 'hire_date' => '2026-04-20']);
    $this->artisan('app:send-probation-alerts');

    $this->travelTo(CarbonImmutable::parse('2026-10-20 07:00'));
    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 1 probation alert.');

    Notification::assertSentToTimes($this->hr, ProbationEnding::class, 2);
    Notification::assertSentTo($this->hr, ProbationEnding::class, fn (ProbationEnding $alert) => $alert->isDue);
});

it('ignores regular staff, missing hire dates and probation far from ending', function () {
    EmployeeProfile::factory()->create(['employment_status' => 'regular', 'hire_date' => '2025-01-01']);
    EmployeeProfile::factory()->create(['employment_status' => 'probationary', 'hire_date' => null]);
    EmployeeProfile::factory()->create(['employment_status' => 'probationary', 'hire_date' => '2026-08-01']);

    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 0 probation alerts.');

    Notification::assertNothingSent();
});

it('does not alert HR staff who have resigned', function () {
    $resigned = employee(['employment_status' => 'resigned'], Role::Hr);
    EmployeeProfile::factory()->create(['employment_status' => 'probationary', 'hire_date' => '2026-04-20']);

    $this->artisan('app:send-probation-alerts');

    Notification::assertNotSentTo($resigned, ProbationEnding::class);
    Notification::assertSentTo($this->hr, ProbationEnding::class);
});

it('emails HR as well as filling the bell', function () {
    $alert = new ProbationEnding(EmployeeProfile::factory()->create(), CarbonImmutable::parse('2026-10-20'), -12);

    expect($alert->via($this->hr))->toBe(['database', 'mail'])
        ->and($alert->toArray($this->hr)['title'])->toStartWith('Probation ending soon');
});

it('reminds HR every week while someone is still Probationary past it, until the status changes', function () {
    // Hired Apr 1 → six months on Oct 1; today is Oct 8.
    $profile = EmployeeProfile::factory()->create(['employment_status' => 'probationary', 'hire_date' => '2026-04-01', 'name' => 'Rico Santos']);

    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 1 probation alert.');
    Notification::assertSentTo($this->hr, ProbationEnding::class, fn (ProbationEnding $alert) => $alert->daysPast === 7
        && $alert->toArray($this->hr)['title'] === 'Probation ended 7 days ago: Rico Santos'
        && str_contains($alert->toArray($this->hr)['body'], 'Art. 296'));

    // Daily runs in between send nothing; the next week, one more.
    foreach (['2026-10-09', '2026-10-14'] as $day) {
        $this->travelTo(CarbonImmutable::parse("{$day} 07:00"));
        $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 0 probation alerts.');
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-15 07:00'));
    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 1 probation alert.');
    Notification::assertSentToTimes($this->hr, ProbationEnding::class, 2);

    // Regularized: no more reminders.
    $profile->forceFill(['employment_status' => 'regular'])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-22 07:00'));
    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 0 probation alerts.');
});

it('words a late first alert as "ended N days ago", not "ends today"', function () {
    // Hired Apr 5 → ended Oct 5; the scheduler wasn't running until Oct 8.
    EmployeeProfile::factory()->create(['employment_status' => 'probationary', 'hire_date' => '2026-04-05', 'name' => 'Lea Cruz']);

    $this->artisan('app:send-probation-alerts');

    Notification::assertSentTo($this->hr, ProbationEnding::class, fn (ProbationEnding $alert) => $alert->toArray($this->hr)['title'] === 'Probation ended 3 days ago: Lea Cruz');
});

it('flags those past probation on the Employees list and HR\'s dashboard', function () {
    $overdue = EmployeeProfile::factory()->create(['employment_status' => 'probationary', 'hire_date' => '2026-04-01']);
    $soon = EmployeeProfile::factory()->create(['employment_status' => 'probationary', 'hire_date' => '2026-04-20']);

    expect($overdue->probationOverdueSince()?->toDateString())->toBe('2026-10-01')
        ->and($soon->probationOverdueSince())->toBeNull()
        ->and(EmployeeProfile::query()->probationOverdue()->pluck('id')->all())->toBe([$overdue->id]);

    $this->actingAs($this->hr)->get(route('employees'))->assertSee('Probation ended Oct 1');

    $tile = collect(app(DashboardHighlightsQuery::class)->handle($this->hr, CarbonImmutable::today()))->firstWhere('label', 'Probation ending');
    expect($tile)->toMatchArray(['value' => '2', 'hint' => '1 already past 6 months']);
});
