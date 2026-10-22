<?php

use App\Actions\Overtime\RecordOvertime;
use App\Actions\Overtime\SubmitOvertimeRequest;
use App\Enums\Region;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Livewire\Admin\OvertimeRequests;
use App\Livewire\Overtime\MyOvertime;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\OvertimeRequest;
use App\Notifications\RequestDecided;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Operations adds a guard's extra time themselves (the guard held the post
// longer): saved approved, marked who added it, and the guard is told.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00'));
    $this->guard = employee(['name' => 'Gina Guard']);
    $this->guard->forceFill(['name' => 'Gina Guard'])->save(); // the account's name, which this list shows
    $this->ops = staff(Role::OperationsHq);
});

it('lets Operations add a guard\'s extra time, approved, and tells the guard', function () {
    Notification::fake();

    Livewire::actingAs($this->ops)->test(OvertimeRequests::class)
        ->assertSee('Add extra time')
        ->call('startExtra')
        ->assertSee('Gina Guard')
        ->set('extraGuardId', (string) $this->guard->id)->set('extraDate', '2026-10-18')->set('extraHours', '2.5')
        ->set('extraReason', 'Held the post until 10 PM: the night guard came late.')
        ->call('recordExtra')
        ->assertHasNoErrors()
        ->assertSet('addingExtra', false);

    $overtime = OvertimeRequest::query()->sole();
    expect($overtime)
        ->user_id->toBe($this->guard->id)
        ->filed_by->toBe($this->ops->id)
        ->status->toBe(RequestStatus::Approved)
        ->approved_minutes->toBe(150)
        ->reviewed_by->toBe($this->ops->id)
        ->and($overtime->sentToLabel())->toBe('Added by '.$this->ops->name)
        ->and(ActivityLog::query()->where('event', 'overtime.recorded')->exists())->toBeTrue();

    Notification::assertSentTo($this->guard, RequestDecided::class, function (RequestDecided $notice) {
        $mail = $notice->toArray($this->guard);

        return str_starts_with($mail['title'], 'Extra time added') && str_contains($mail['body'], 'added 2.5 hours of extra time for you: Held the post until 10 PM');
    });

    // The guard sees it on My Overtime, with who added it.
    Livewire::actingAs($this->guard)->test(MyOvertime::class)->assertSee('Added by '.$this->ops->name);
});

it('checks the guard, the date, the hours and a reason', function () {
    $page = Livewire::actingAs($this->ops)->test(OvertimeRequests::class)->call('startExtra');

    $page->call('recordExtra')->assertHasErrors(['extraGuardId', 'extraHours', 'extraReason']);
    $page->set('extraGuardId', (string) $this->guard->id)->set('extraDate', '2026-10-20')->set('extraHours', '13')->set('extraReason', 'Late relief')
        ->call('recordExtra')->assertHasErrors(['extraDate', 'extraHours']);   // not worked yet; more than 12 hours

    // Office staff aren't in the list (their overtime goes to the Executive).
    $page->set('extraGuardId', (string) staff(Role::Accounting)->id)->set('extraDate', '2026-10-18')->set('extraHours', '1')
        ->call('recordExtra')->assertHasErrors(['extraGuardId']);

    expect(OvertimeRequest::query()->count())->toBe(0);
});

it('sends Operations to the guard\'s own request instead of adding a second one', function () {
    app(SubmitOvertimeRequest::class)->handle($this->guard, CarbonImmutable::parse('2026-10-18'), 60, 'Stayed late', null);

    expect(fn () => app(RecordOvertime::class)->handle($this->ops, $this->guard, CarbonImmutable::parse('2026-10-18'), 120, 'Held the post'))
        ->toThrow(ValidationException::class, 'already asked for overtime on Oct 18: review that request instead');
});

it('is for those who decide guards\' overtime, in their own region', function () {
    // HR and Admin decide guards' overtime too; Accounting and guards don't.
    Livewire::actingAs(staff(Role::Hr))->test(OvertimeRequests::class)->assertSee('Add extra time');
    expect(fn () => app(RecordOvertime::class)->handle(staff(Role::Accounting), $this->guard, CarbonImmutable::parse('2026-10-18'), 60, 'Held the post'))
        ->toThrow(ValidationException::class, 'Only Operations, HR or Admin');

    // A Satellite Operation only for guards in its region.
    $satellite = staff(Role::SatelliteOperations);
    $satellite->forceFill(['operations_region' => Region::Calabarzon])->save();
    $makati = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);
    $this->guard->employeeProfile?->forceFill(['client_id' => $makati->id])->save();

    Livewire::actingAs($satellite)->test(OvertimeRequests::class)->call('startExtra')->assertDontSee('Gina Guard');
    expect(fn () => app(RecordOvertime::class)->handle($satellite, $this->guard->fresh(), CarbonImmutable::parse('2026-10-18'), 60, 'Held the post'))
        ->toThrow(ValidationException::class, 'outside your region');
});
