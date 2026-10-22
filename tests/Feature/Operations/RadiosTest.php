<?php

use App\Actions\Operations\SendRadioPermitAlerts;
use App\Actions\Resignations\AcceptResignation;
use App\Actions\Resignations\FileResignation;
use App\Enums\RadioStatus;
use App\Enums\Region;
use App\Enums\ResignationReason;
use App\Enums\Role;
use App\Livewire\Admin\Radios;
use App\Livewire\Admin\Resignations;
use App\Models\Client;
use App\Models\Radio;
use App\Notifications\RadioPermitDue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// The NTC radio masterlist: Admin and Operation HQ keep it; permits are alerted before they expire.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-22 07:20'));
    $this->admin = staff(Role::Admin);
    $this->client = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);
});

function radioForm(object $test, array $fields = [])
{
    $page = Livewire::actingAs($test->admin)->test(Radios::class)->call('create');
    foreach (['brand' => 'Motorola', 'model' => 'XiR P3688', 'serialNumber' => '123abc', 'permitNumber' => 'NTC-2026-001', 'permitExpiresOn' => '2026-11-20', 'status' => 'spare', ...$fields] as $field => $value) {
        $page->set($field, $value);
    }

    return $page->call('save');
}

it('lets Admin and Operation HQ keep the list, not HR', function () {
    radioForm($this)->assertHasNoErrors();
    expect(Radio::query()->sole())->serial_number->toBe('123ABC')->status->toBe(RadioStatus::Spare);

    $this->actingAs(staff(Role::OperationsHq))->get(route('radios'))->assertOk();
    $this->actingAs(staff(Role::Hr))->get(route('radios'))->assertForbidden();
});

it('puts a radio in use at a post or with a guard, and back in stock otherwise', function () {
    $guard = employee();

    radioForm($this, ['status' => 'in_use'])->assertHasErrors('clientId');
    radioForm($this, ['status' => 'in_use', 'clientId' => (string) $this->client->id, 'assignedUserId' => (string) $guard->id])->assertHasNoErrors();
    radioForm($this, ['serialNumber' => '123ABC'])->assertHasErrors('serialNumber'); // already listed

    $radio = Radio::query()->sole();
    expect($radio->whereabouts())->toBe("{$guard->name} · Makati CBD");

    Livewire::actingAs($this->admin)->test(Radios::class)->call('edit', $radio->id)->set('status', 'for_repair')->call('save')->assertHasNoErrors();
    expect($radio->fresh())->client_id->toBeNull()->assigned_user_id->toBeNull();
});

it('warns about a permit expiring, once per step, by email from 30 days', function () {
    radioForm($this)->assertHasNoErrors(); // expires Nov 20: 29 days away
    $hq = staff(Role::OperationsHq);

    expect(app(SendRadioPermitAlerts::class)->handle(CarbonImmutable::today()))->toBe(1)
        ->and(app(SendRadioPermitAlerts::class)->handle(CarbonImmutable::today()))->toBe(0);

    Notification::assertSentTo([$this->admin, $hq], RadioPermitDue::class, fn (RadioPermitDue $notice) => $notice->email
        && $notice->toArray($hq)['title'] === 'NTC permit expires in 29 days: radio SN 123ABC');

    // A disposed radio's permit no longer matters.
    Radio::query()->update(['status' => 'disposed']);
    expect(app(SendRadioPermitAlerts::class)->handle(CarbonImmutable::parse('2026-11-14')))->toBe(0);
});

it('shows a guard\'s radio on their resignation clearance', function () {
    $guard = employee();
    radioForm($this, ['status' => 'in_use', 'assignedUserId' => (string) $guard->id])->assertHasNoErrors();

    $hr = staff(Role::Hr);
    $resignation = app(FileResignation::class)->handle($guard, ResignationReason::AnotherJob, null, 'Leaving next month for another job.', CarbonImmutable::parse('2026-11-22'), false);
    app(AcceptResignation::class)->handle($resignation, $hr, CarbonImmutable::parse('2026-11-22'), null);

    Livewire::actingAs($hr)->test(Resignations::class)->call('open', $resignation->id)
        ->assertSee('Radio still with them: Motorola XiR P3688 (SN 123ABC)');
});
