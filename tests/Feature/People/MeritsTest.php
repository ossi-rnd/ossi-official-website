<?php

use App\Actions\People\GiveMerit;
use App\Actions\People\ReplyToDemerit;
use App\Actions\People\SaveMeritType;
use App\Actions\People\VoidMerit;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\Merits;
use App\Livewire\People\MeritHistory;
use App\Models\Client;
use App\Models\MeritRecord;
use App\Models\MeritType;
use App\Notifications\MeritNotice;
use App\Queries\People\MeritTotalsQuery;
use App\Queries\People\PeopleToActOnQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Merits and demerits (roadmap 21): given by whoever supervises the person, with a
// reason and the type's points; the employee is told and can add their side to a
// demerit; voided with a reason, never deleted; 12-month totals; no pay effect.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00'));

    $makati = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);
    $imus = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);

    $this->guard = employee(['client_id' => $makati->id]);
    $this->cavite = employee(['client_id' => $imus->id]);
    $this->hr = staff(Role::Hr);
    $this->otherHr = staff(Role::Hr);
    $this->opsHq = staff(Role::OperationsHq);
    $this->satellite = staff(Role::SatelliteOperations);
    $this->satellite->forceFill(['operations_region' => Region::Ncr])->save();
    $this->executive = staff(Role::President);
    $this->accountant = staff(Role::Accounting);
    $this->adminStaff = staff(Role::Admin);

    $this->uniform = MeritType::query()->where('name', 'Out of uniform')->sole();
    $this->commended = MeritType::query()->where('name', 'Commended by the client')->sole();
});

it('is given by whoever supervises the person', function () {
    expect(MeritRecord::canGive($this->opsHq, $this->guard))->toBeTrue()
        ->and(MeritRecord::canGive($this->satellite, $this->guard))->toBeTrue()
        ->and(MeritRecord::canGive($this->satellite, $this->cavite))->toBeFalse() // another region
        ->and(MeritRecord::canGive($this->hr, $this->guard))->toBeTrue()
        ->and(MeritRecord::canGive($this->hr, $this->adminStaff))->toBeTrue() // Admin: HR
        ->and(MeritRecord::canGive($this->hr, $this->accountant))->toBeFalse() // Accounting: the President or VP
        ->and(MeritRecord::canGive($this->executive, $this->accountant))->toBeTrue()
        ->and(MeritRecord::canGive($this->opsHq, $this->accountant))->toBeFalse()
        ->and(MeritRecord::canGive($this->hr, $this->otherHr))->toBeFalse() // HR staff: the Executive
        ->and(MeritRecord::canGive($this->executive, $this->otherHr))->toBeTrue()
        ->and(MeritRecord::canGive($this->accountant, $this->guard))->toBeFalse()
        ->and(MeritRecord::canGive($this->hr, $this->hr))->toBeFalse();

    expect(fn () => app(GiveMerit::class)->handle($this->satellite, $this->cavite, $this->uniform, 'Out of uniform at the gate.', CarbonImmutable::today()))
        ->toThrow(AuthorizationException::class);
});

it('copies the type\'s points, tells the employee, and opens 5 days for their side of a demerit', function () {
    $demerit = app(GiveMerit::class)->handle($this->opsHq, $this->guard, $this->uniform, 'No necktie during inspection.', CarbonImmutable::parse('2026-10-15'));

    expect($demerit)->kind->toBe('demerit')->points->toBe(-1)->title->toBe('Out of uniform')
        ->and($demerit->reply_until?->toDateString())->toBe('2026-10-21')
        ->and($demerit->awaitsReply())->toBeTrue();
    Notification::assertSentTo($this->guard, MeritNotice::class, fn (MeritNotice $notice) => $notice->event === MeritNotice::GIVEN
        && $notice->toArray($this->guard)['title'] === 'Demerit: Out of uniform (-1)'
        && str_contains($notice->toArray($this->guard)['body'], 'until Oct 21'));

    // A type changed later doesn't rewrite it.
    app(SaveMeritType::class)->handle($this->hr, $this->uniform, 'Out of uniform', 'demerit', 3, null);
    expect($demerit->fresh()?->points)->toBe(-1);
});

it('takes "Other" with its own title and points, and refuses a missing reason or a future date', function () {
    $merit = app(GiveMerit::class)->handle($this->hr, $this->guard, null, 'Walked a lost child to the mall office.', CarbonImmutable::today(), 'Helped a lost child', 3);
    expect($merit)->kind->toBe('merit')->points->toBe(3)->reply_until->toBeNull();

    expect(fn () => app(GiveMerit::class)->handle($this->hr, $this->guard, $this->commended, '', CarbonImmutable::today()))->toThrow(ValidationException::class, 'what happened');
    expect(fn () => app(GiveMerit::class)->handle($this->hr, $this->guard, $this->commended, 'Thanked by the client.', CarbonImmutable::tomorrow()))->toThrow(ValidationException::class, 'future');
    expect(fn () => app(GiveMerit::class)->handle($this->hr, $this->guard, null, 'Something good.', CarbonImmutable::today(), 'Good', 40))->toThrow(ValidationException::class, '1 to 20');
});

it('lets the employee add their side once, within 5 days, and tells whoever gave it', function () {
    $demerit = app(GiveMerit::class)->handle($this->opsHq, $this->guard, $this->uniform, 'No necktie during inspection.', CarbonImmutable::today());

    expect(fn () => app(ReplyToDemerit::class)->handle($this->cavite, $demerit, 'Not me.'))->toThrow(AuthorizationException::class);
    app(ReplyToDemerit::class)->handle($this->guard, $demerit, 'My necktie was torn; Sgt. Reyes knew and allowed it.');
    Notification::assertSentTo($this->opsHq, MeritNotice::class, fn (MeritNotice $notice) => $notice->event === MeritNotice::REPLIED);
    expect(fn () => app(ReplyToDemerit::class)->handle($this->guard, $demerit->fresh(), 'Again.'))->toThrow(ValidationException::class, 'any more');

    // Too late.
    $late = app(GiveMerit::class)->handle($this->opsHq, $this->guard, $this->uniform, 'No ID on duty.', CarbonImmutable::today());
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00'));
    expect(fn () => app(ReplyToDemerit::class)->handle($this->guard, $late, 'My ID was being renewed.'))->toThrow(ValidationException::class, 'any more');
});

it('counts the last 12 months only, standing records only, and a void needs a reason', function () {
    $give = app(GiveMerit::class);
    $give->handle($this->hr, $this->guard, $this->commended, 'Thanked by the building admin.', CarbonImmutable::today());
    $wrong = $give->handle($this->hr, $this->guard, $this->uniform, 'Wrong guard.', CarbonImmutable::today());
    $old = $give->handle($this->hr, $this->guard, $this->uniform, 'Old one.', CarbonImmutable::today()->subDays(80));
    $old->forceFill(['occurred_on' => '2025-09-01'])->save(); // more than 12 months ago

    expect(fn () => app(VoidMerit::class)->handle($this->hr, $wrong, ''))->toThrow(ValidationException::class, 'why');
    app(VoidMerit::class)->handle($this->hr, $wrong, 'Given to the wrong guard.');
    expect(fn () => app(VoidMerit::class)->handle($this->accountant, $wrong->fresh(), 'No.'))->toThrow(AuthorizationException::class);

    expect(app(MeritTotalsQuery::class)->handle([$this->guard->id])[$this->guard->id])->toBe(['merits' => 5, 'demerits' => 0, 'net' => 5])
        ->and(MeritRecord::query()->count())->toBe(3); // nothing deleted
});

it('picks people by name or employee number, and suggests some before anything is typed', function () {
    $number = (string) $this->cavite->employeeProfile?->employee_number;

    // Operation HQ: by employee number.
    expect(app(PeopleToActOnQuery::class)->handle($this->opsHq, $number, fn ($user) => MeritRecord::canGive($this->opsHq, $user))->pluck('id')->all())->toBe([$this->cavite->id]);

    // Nothing typed: the people they supervise (a Satellite: its region's only)...
    expect(app(PeopleToActOnQuery::class)->handle($this->satellite, '', fn ($user) => MeritRecord::canGive($this->satellite, $user))->pluck('id')->all())->toBe([$this->guard->id]);

    // ...or the site of the last one they gave.
    app(GiveMerit::class)->handle($this->opsHq, $this->cavite, $this->commended, 'Thanked by the branch manager.', CarbonImmutable::today());
    Livewire::actingAs($this->opsHq)->test(Merits::class)
        ->call('startGiving')
        ->assertSee('Suggested, or search above')
        ->assertSee($this->cavite->name)
        ->assertDontSee($this->guard->name);
});

it('works from the pages, and each viewer sees only their people', function () {
    app(GiveMerit::class)->handle($this->executive, $this->accountant, $this->commended, 'Closed the books early.', CarbonImmutable::today());

    Livewire::actingAs($this->opsHq)->test(Merits::class, ['giveTo' => $this->guard->id])
        ->assertSet('giving', true)
        ->assertDontSee($this->accountant->name) // office staff: not Operations'
        ->set('typeId', (string) $this->uniform->id)
        ->set('reason', 'No necktie during inspection.')
        ->call('give')
        ->assertHasNoErrors();
    expect(MeritRecord::query()->where('user_id', $this->guard->id)->count())->toBe(1);

    Livewire::actingAs($this->guard)->test(MeritHistory::class, ['userId' => $this->guard->id])
        ->assertSee('Out of uniform')
        ->assertSee('Add my side');

    $this->actingAs($this->guard)->get(route('merits'))->assertForbidden();
    $this->actingAs($this->accountant)->get(route('merits'))->assertForbidden();
    Livewire::actingAs($this->accountant)->test(MeritHistory::class, ['userId' => $this->guard->id])->assertForbidden();
});
