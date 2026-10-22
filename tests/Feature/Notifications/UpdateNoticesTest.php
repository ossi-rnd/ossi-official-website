<?php

use App\Actions\Auth\ClaimEmployeeAccount;
use App\Actions\Content\SavePolicy;
use App\Actions\Operations\SaveRadio;
use App\Actions\People\DeployEmployee;
use App\Actions\People\SetEmployeeRate;
use App\Actions\Roles\SyncUserRoles;
use App\Actions\Roster\RemoveDuty;
use App\Actions\Roster\SaveDuty;
use App\Actions\Training\MarkTrainingResult;
use App\Actions\Training\SaveTraining;
use App\Enums\RadioStatus;
use App\Enums\Region;
use App\Enums\Role;
use App\Enums\TrainingAttendance;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Models\TrainingAttendee;
use App\Notifications\UpdateNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

// The audit of "who is affected and never told" (2026-10-22): each of these now sends the
// affected person a bell (UpdateNotice), never the one who made the change.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00'));
    $this->client = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);
    $this->shift = Shift::query()->create(['name' => 'Day Duty', 'start_time' => '06:00', 'end_time' => '15:00', 'grace_minutes' => 10]);
    $this->guard = employee(['client_id' => $this->client->id, 'detachment' => 'Makati CBD']);
});

function told(object $user, string $title): void
{
    Notification::assertSentTo($user, UpdateNotice::class, fn (UpdateNotice $notice) => str_starts_with($notice->title, $title));
}

it('tells a guard when they are moved, and when a duty is set or removed', function () {
    $hq = staff(Role::OperationsHq);
    $other = Client::query()->create(['region' => Region::Ncr, 'province' => 'Taguig', 'name' => 'BGC Tower One']);

    app(DeployEmployee::class)->handle($this->guard->employeeProfile, $other, 'Lobby', $this->shift, staff(Role::Hr));
    Notification::assertSentTo($this->guard, UpdateNotice::class, fn (UpdateNotice $notice) => $notice->title === 'Your deployment changed'
        && str_contains($notice->body, 'BGC Tower One · Lobby') && $notice->email);

    $duty = app(SaveDuty::class)->handle($this->guard->fresh(), CarbonImmutable::parse('2026-10-24'), $this->shift, $other, 'Lobby', $hq);
    told($this->guard, 'Duty set: Sat, Oct 24');

    app(RemoveDuty::class)->handle($duty, $hq);
    told($this->guard, 'Day off: Sat, Oct 24');
    Notification::assertNotSentTo($hq, UpdateNotice::class);
});

it('tells the employee their new daily rate', function () {
    app(SetEmployeeRate::class)->handle($this->guard->employeeProfile, 780, CarbonImmutable::parse('2026-11-01'), null, staff(Role::Accounting));

    told($this->guard, 'Your daily rate: ₱780.00');
});

it('tells someone their access changed, but not when they change their own', function () {
    $superAdmin = staff(Role::SuperAdmin);
    $clerk = staff(Role::Accounting);

    app(SyncUserRoles::class)->handle($clerk, [Role::Employee, Role::Hr], $superAdmin);
    Notification::assertSentTo($clerk, UpdateNotice::class, fn (UpdateNotice $notice) => $notice->title === 'Your access changed' && str_contains($notice->body, 'Your role: HR'));
    Notification::assertNotSentTo($superAdmin, UpdateNotice::class);
});

it('tells everyone of a new or changed company policy, once', function () {
    $hr = staff(Role::Hr);

    $policy = app(SavePolicy::class)->handle(null, ['title' => 'Attendance and punctuality', 'body' => 'Be at your post 15 minutes early.', 'sort_order' => 10], null, false, $hr);
    told($this->guard, 'New company policy: Attendance and punctuality');
    Notification::assertNotSentTo($hr, UpdateNotice::class);

    // Only the order changed: nobody needs to read it again.
    Notification::fake();
    app(SavePolicy::class)->handle($policy, ['title' => 'Attendance and punctuality', 'body' => 'Be at your post 15 minutes early.', 'sort_order' => 20], null, false, $hr);
    Notification::assertNothingSent();
});

it('tells HR when someone registers', function () {
    $hr = staff(Role::Hr);
    $profile = EmployeeProfile::factory()->create(['name' => 'New Hire', 'email' => 'new@example.test', 'user_id' => null]);

    app(ClaimEmployeeAccount::class)->handle($profile->employee_number, 'new@example.test', 'Str0ng!Password');

    told($hr, 'New Hire registered');
});

it('tells a guard a radio was issued to them', function () {
    app(SaveRadio::class)->handle(null, [
        'brand' => 'Motorola', 'model' => 'XiR P3688', 'serial_number' => 'abc123', 'frequency' => null, 'ntc_permit_number' => null,
        'permit_expires_on' => null, 'status' => RadioStatus::InUse, 'client_id' => null, 'assigned_user_id' => $this->guard->id, 'notes' => null,
    ], staff(Role::Admin));

    Notification::assertSentTo($this->guard, UpdateNotice::class, fn (UpdateNotice $notice) => str_contains($notice->body, 'ABC123'));
});

it('tells trainees when a session moves and when they are graded', function () {
    $admin = staff(Role::Admin);
    $data = ['title' => 'Firearms refresher', 'provider' => null, 'starts_on' => '2026-11-05', 'ends_on' => null, 'venue' => 'HQ', 'valid_until' => null, 'notes' => null];
    $training = app(SaveTraining::class)->handle(null, $data, $admin);
    $attendee = new TrainingAttendee;
    $attendee->forceFill(['training_id' => $training->id, 'employee_profile_id' => $this->guard->employeeProfile?->id, 'status' => TrainingAttendance::Enrolled])->save();

    app(SaveTraining::class)->handle($training, [...$data, 'starts_on' => '2026-11-12'], $admin);
    told($this->guard, 'Training moved: Firearms refresher');

    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00'));
    app(MarkTrainingResult::class)->handle($attendee, TrainingAttendance::Completed, $admin);
    told($this->guard, 'Firearms refresher: Completed');
});
