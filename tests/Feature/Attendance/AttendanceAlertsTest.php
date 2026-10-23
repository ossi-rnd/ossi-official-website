<?php

use App\Actions\Attendance\SaveAttendanceAlertRules;
use App\Actions\Attendance\SendAttendanceAlerts;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Attendance\AlertRules;
use App\Models\Client;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Notifications\UpdateNotice;
use App\Support\AttendanceAlertRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Missed clock-outs (the employee, then their head) and office staff who haven't clocked in
// (the employee, then one summary to each head; HR is also told about office staff it doesn't
// head, as timekeeper). Rules: HR, in the app.

beforeEach(function () {
    Notification::fake();
    $this->shift = Shift::query()->create(['name' => 'Office', 'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 10]);
    $this->president = staff(Role::President);
    $this->hq = staff(Role::OperationsHq);
    $this->ncrSatellite = staff(Role::SatelliteOperations);
    $this->ncrSatellite->forceFill(['operations_region' => Region::Ncr])->save();
    $this->otherSatellite = staff(Role::SatelliteOperations);
    $this->otherSatellite->forceFill(['operations_region' => Region::Calabarzon])->save();
});

function alertOpenLog(object $user, Shift $shift, string $date, string $in): TimeLog
{
    return TimeLog::factory()->create(['user_id' => $user->id, 'shift_id' => $shift->id, 'log_date' => $date, 'clock_in_at' => "{$date} {$in}", 'clock_out_at' => null]);
}

function titled(string $title): Closure
{
    return fn (UpdateNotice $notice) => str_starts_with($notice->title, $title);
}

it('reminds a guard who forgot to clock out, then tells Operations of their region, once each', function () {
    $client = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);
    $guard = employee(['client_id' => $client->id, 'shift_id' => $this->shift->id]);
    alertOpenLog($guard, $this->shift, '2026-10-22', '07:55');

    // 17:20: not yet (the reminder is at 17:30).
    expect(app(SendAttendanceAlerts::class)->handle(CarbonImmutable::parse('2026-10-22 17:20'))['reminded'])->toBe(0);

    app(SendAttendanceAlerts::class)->handle(CarbonImmutable::parse('2026-10-22 17:35'));
    Notification::assertSentTo($guard, UpdateNotice::class, titled('You haven\'t clocked out'));
    Notification::assertNotSentTo($this->hq, UpdateNotice::class);

    $later = app(SendAttendanceAlerts::class)->handle(CarbonImmutable::parse('2026-10-22 19:05'));
    expect($later)->reminded->toBe(0)->escalated->toBe(1);
    Notification::assertSentTo([$this->hq, $this->ncrSatellite], UpdateNotice::class, titled("Not clocked out: {$guard->name}"));
    Notification::assertNotSentTo([$this->otherSatellite, $this->president], UpdateNotice::class);

    expect(app(SendAttendanceAlerts::class)->handle(CarbonImmutable::parse('2026-10-22 20:00'))['escalated'])->toBe(0);
});

it('tells each office staffer\'s own head about a missed clock-out, and HR as timekeeper', function () {
    $hr = staff(Role::Hr);
    $clerk = staff(Role::Accounting);
    $admin = staff(Role::Admin);
    $headOfOperations = staff(Role::OperationsHq);
    foreach ([$hr, $clerk, $admin, $this->ncrSatellite, $headOfOperations] as $person) {
        alertOpenLog($person, $this->shift, '2026-10-22', '08:02');
    }

    app(SendAttendanceAlerts::class)->handle(CarbonImmutable::parse('2026-10-22 19:30'));

    // Accounting and Operation HQ → the President or VP; HR is told too (informed).
    foreach ([$clerk, $headOfOperations] as $person) {
        Notification::assertSentTo([$this->president, $hr], UpdateNotice::class, titled("Not clocked out: {$person->name}"));
    }
    // Admin → HR only.
    Notification::assertSentTo($hr, UpdateNotice::class, titled("Not clocked out: {$admin->name}"));
    Notification::assertNotSentTo($this->president, UpdateNotice::class, titled("Not clocked out: {$admin->name}"));
    // HR → the President or VP.
    Notification::assertSentTo($this->president, UpdateNotice::class, titled("Not clocked out: {$hr->name}"));
    // A Satellite → Operation HQ; HR informed; not the President or VP.
    Notification::assertSentTo([$this->hq, $hr], UpdateNotice::class, titled("Not clocked out: {$this->ncrSatellite->name}"));
    Notification::assertNotSentTo($this->president, UpdateNotice::class, titled("Not clocked out: {$this->ncrSatellite->name}"));
});

it('reminds office staff who haven\'t clocked in, with one summary to each head', function () {
    $hrHead = staff(Role::Hr);
    $late = staff(Role::Accounting);
    $alsoLate = staff(Role::Admin);
    $onLeave = staff(Role::Accounting);
    $in = staff(Role::Accounting);
    $lateHr = staff(Role::Hr);
    foreach ([$late, $alsoLate, $onLeave, $in, $lateHr] as $person) {
        $person->employeeProfile?->forceFill(['shift_id' => $this->shift->id])->save();
    }
    $leave = new LeaveRequest(['type' => 'vacation', 'start_date' => '2026-10-22', 'end_date' => '2026-10-22', 'reason' => 'Rest']);
    $leave->user()->associate($onLeave);
    $leave->forceFill(['status' => 'approved'])->save();
    alertOpenLog($in, $this->shift, '2026-10-22', '08:00');

    // Thursday 08:45: past 08:00 + 10 min grace + 30 min.
    expect(app(SendAttendanceAlerts::class)->handle(CarbonImmutable::parse('2026-10-22 08:45'))['officeMissing'])->toBe(3);

    Notification::assertSentTo([$late, $alsoLate, $lateHr], UpdateNotice::class, titled('You haven\'t clocked in today'));
    Notification::assertNotSentTo([$onLeave, $in], UpdateNotice::class);

    // HR hears about Admin (theirs) and Accounting (informed) in one bell; the President or VP
    // about Accounting and HR, never Admin.
    Notification::assertSentToTimes($hrHead, UpdateNotice::class, 1);
    Notification::assertSentTo($hrHead, UpdateNotice::class, fn (UpdateNotice $notice) => $notice->title === '2 not clocked in yet' && ! str_contains($notice->body, $lateHr->name));
    Notification::assertSentTo($this->president, UpdateNotice::class, fn (UpdateNotice $notice) => $notice->title === '2 not clocked in yet'
        && str_contains($notice->body, $lateHr->name) && str_contains($notice->body, $late->name) && ! str_contains($notice->body, $alsoLate->name));

    // Not again the same day.
    expect(app(SendAttendanceAlerts::class)->handle(CarbonImmutable::parse('2026-10-22 10:00'))['officeMissing'])->toBe(0);
});

it('stays quiet on a rest day or a holiday', function () {
    staff(Role::Accounting)->employeeProfile?->forceFill(['shift_id' => $this->shift->id])->save();

    // Saturday is not an office day by default.
    expect(app(SendAttendanceAlerts::class)->handle(CarbonImmutable::parse('2026-10-24 09:00'))['officeMissing'])->toBe(0);

    Holiday::query()->create(['date' => '2026-10-23', 'name' => 'Test holiday', 'type' => 'special_non_working']);
    expect(app(SendAttendanceAlerts::class)->handle(CarbonImmutable::parse('2026-10-23 09:00'))['officeMissing'])->toBe(0);
});

it('lets HR change the rules, audited, and nobody else', function () {
    $hr = staff(Role::Hr);

    expect(fn () => app(SaveAttendanceAlertRules::class)->handle(staff(Role::OperationsHq), 30, 120, 30, [1, 2, 3]))->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveAttendanceAlertRules::class)->handle($hr, 60, 45, 30, [1]))->toThrow(ValidationException::class, 'Later than the reminder');

    Livewire::actingAs($hr)->test(AlertRules::class)
        ->call('edit')->set('clockOutReminder', '20')->set('clockOutEscalate', '90')->set('officeClockInReminder', '15')
        ->set('officeWorkDays', ['1', '2', '3', '4', '5', '6'])->call('save')->assertHasNoErrors();

    expect(AttendanceAlertRules::all())->toBe(['clock_out_reminder_minutes' => 20, 'clock_out_escalate_minutes' => 90, 'office_clock_in_reminder_minutes' => 15, 'office_work_days' => [1, 2, 3, 4, 5, 6]])
        ->and(AttendanceAlertRules::daysLabel())->toBe('Mon–Sat');

    Livewire::actingAs($this->hq)->test(AlertRules::class)->assertSee('Mon–Sat')->call('edit')->assertForbidden();
});
