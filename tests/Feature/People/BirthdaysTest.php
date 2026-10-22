<?php

use App\Actions\People\SendBirthdayReminders;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\AttendanceDashboard;
use App\Livewire\Admin\EmployeeDetail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Notifications\BirthdayToday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// Birthday reminders: HR enters the date of birth (encrypted, it reveals age); on the
// day HR, and for a guard Operations, get a bell. Never the age; never if kept private.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-22 07:15'));

    $this->hr = staff(Role::Hr);
    $this->hq = staff(Role::OperationsHq);
    $this->ncrSatellite = staff(Role::SatelliteOperations);
    $this->ncrSatellite->forceFill(['operations_region' => Region::Ncr])->save();
    $this->otherSatellite = staff(Role::SatelliteOperations);
    $this->otherSatellite->forceFill(['operations_region' => Region::Calabarzon])->save();

    $makati = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);
    $this->guard = employee(['client_id' => $makati->id, 'detachment' => 'Makati CBD', 'birth_date' => '1995-10-22']);
});

it('keeps the date of birth encrypted, out of the audit log, and refuses minors', function () {
    $profile = $this->guard->employeeProfile;

    expect(DB::table('employee_profiles')->where('id', $profile?->id)->value('birth_date'))->not->toContain('1995');

    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')->set('form.birth_date', CarbonImmutable::today()->subYears(17)->toDateString())->call('save')
        ->assertHasErrors(['form.birth_date' => 'They must be at least 18.'])
        ->set('form.birth_date', '1990-03-15')->call('save')->assertHasNoErrors();

    expect($profile?->fresh()?->birthDate()?->toDateString())->toBe('1990-03-15')
        ->and(json_encode(ActivityLog::query()->where('event', 'profile.updated')->latest('id')->first()?->properties))->not->toContain('1990')->toContain('(changed)');
});

it('tells HR, Operation HQ and the guard\'s own Satellite on the day, once', function () {
    expect(app(SendBirthdayReminders::class)->handle(CarbonImmutable::today()))->toBe(1);

    Notification::assertSentTo([$this->hr, $this->hq, $this->ncrSatellite], BirthdayToday::class,
        fn (BirthdayToday $notice) => $notice->toArray($this->hr)['title'] === "Birthday today: {$this->guard->name}" && ! str_contains($notice->toArray($this->hr)['body'], '31'));
    Notification::assertNotSentTo([$this->otherSatellite, $this->guard], BirthdayToday::class);

    // The scheduler running again sends nothing more.
    expect(app(SendBirthdayReminders::class)->handle(CarbonImmutable::today()))->toBe(0);
});

it('tells only HR about office staff, and nobody when kept private', function () {
    $clerk = staff(Role::Accounting);
    $clerk->employeeProfile?->forceFill(['birth_date' => '1990-10-22'])->save();
    $this->guard->employeeProfile?->forceFill(['birthday_private' => true])->save();

    app(SendBirthdayReminders::class)->handle(CarbonImmutable::today());

    Notification::assertSentTo($this->hr, BirthdayToday::class, fn (BirthdayToday $notice) => $notice->profile->is($clerk->employeeProfile));
    Notification::assertNotSentTo($this->hq, BirthdayToday::class);
    Notification::assertSentToTimes($this->hr, BirthdayToday::class, 1);
});

it('greets a Feb 29 birthday on Feb 28 in a common year', function () {
    $profile = new EmployeeProfile(['birth_date' => '2000-02-29']);

    expect($profile->birthdayIn(2027)?->toDateString())->toBe('2027-02-28')
        ->and($profile->birthdayIn(2028)?->toDateString())->toBe('2028-02-29');
});

it('lists the month\'s birthdays on the dashboard for HR and Operations, not Accounting', function () {
    Livewire::actingAs($this->hr)->test(AttendanceDashboard::class)->assertSee('Birthdays this month')->assertSee($this->guard->name);
    Livewire::actingAs($this->otherSatellite)->test(AttendanceDashboard::class)->assertDontSee('Birthdays this month');
    Livewire::actingAs(staff(Role::Accounting))->test(AttendanceDashboard::class)->assertDontSee('Birthdays this month');
});
