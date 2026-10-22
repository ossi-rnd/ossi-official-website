<?php

use App\Actions\Attendance\RecordPunch;
use App\Actions\Patrols\UpdateAwayLocation;
use App\Enums\PunchType;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Offices;
use App\Models\AwayLog;
use App\Models\Client;
use App\Models\Office;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// OSSI's own offices: where office staff work and clock in. Kept apart from clients
// (guards' sites); Admin and Super Admin manage them; HR picks one on the record.

function office(array $attributes = []): Office
{
    return Office::query()->create([
        'region' => Region::Ncr, 'province' => 'Quezon City', 'name' => 'OSSI Head Office',
        'latitude' => 14.6671, 'longitude' => 121.0162, 'geofence_meters' => 100, ...$attributes,
    ]);
}

it('lets Admin and Super Admin add offices, but not Operation HQ or HR', function () {
    foreach ([Role::Admin, Role::SuperAdmin] as $index => $role) {
        Livewire::actingAs(staff($role))->test(Offices::class)
            ->call('create')
            ->set('region', Region::Ncr->value)->set('province', 'Quezon City')->set('name', 'Branch '.$index)
            ->set('coordinates', '14.6671, 121.0162')->set('geofence_meters', '120')
            ->call('save')
            ->assertHasNoErrors();
    }
    expect(Office::query()->count())->toBe(2)
        ->and(Office::query()->first()?->hasGeofence())->toBeTrue();

    $admin = staff(Role::Admin);
    $management = collect(Navigation::for($admin))->firstWhere('title', 'Management');
    expect(array_column($management['items'] ?? [], 'label'))->toContain('OSSI Offices');

    foreach ([Role::OperationsHq, Role::Hr] as $role) {
        $this->actingAs(staff($role))->get(route('offices'))->assertForbidden();
        Livewire::actingAs(staff($role))->test(Offices::class)->call('create')->assertForbidden();
    }
});

it('keeps offices out of the client lists guards are deployed from', function () {
    office();
    Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => employee()->employeeProfile])
        ->call('edit')
        ->assertSee('Makati CBD')->assertDontSee('OSSI Head Office');
});

it('lets HR set an office staffer\'s office, and clears it for a guard', function () {
    $headOffice = office();
    $clerk = staff(Role::Accounting);

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $clerk->employeeProfile])
        ->call('edit')
        ->assertSee('OSSI Head Office')
        ->set('form.office_id', (string) $headOffice->id)
        ->call('save')->assertHasNoErrors()
        ->assertSee('OSSI Head Office · Quezon City, NCR');
    expect($clerk->employeeProfile?->fresh()?->office_id)->toBe($headOffice->id);

    // Made field staff: an office doesn't apply.
    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $clerk->employeeProfile])
        ->call('edit')->set('form.is_office_staff', false)->call('save')->assertHasNoErrors();
    expect($clerk->employeeProfile?->fresh()?->office_id)->toBeNull();
});

it('makes office staff clock in at their office', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:05'));
    $clerk = staff(Role::Accounting);
    $clerk->employeeProfile?->forceFill(['office_id' => office()->id])->save();

    // About 3 km away: refused, and told where.
    expect(fn () => app(RecordPunch::class)->handle($clerk, PunchType::ClockIn, cameraPhoto(), 14.6941, 121.0162, 10))
        ->toThrow(ValidationException::class, 'from OSSI Head Office. Clock in at the office.');

    // At the office: in.
    $log = app(RecordPunch::class)->handle($clerk->fresh() ?? $clerk, PunchType::ClockIn, cameraPhoto(), 14.6672, 121.0163, 10);
    expect($log->clock_in_at)->not->toBeNull();

    // An office staffer with no office set: anywhere, as before.
    $remote = staff(Role::Hr);
    expect(app(RecordPunch::class)->handle($remote, PunchType::ClockIn, cameraPhoto(), 10.3157, 123.8854, 10)->clock_in_at)->not->toBeNull();

    CarbonImmutable::setTestNow();
});

it('shows the staffer\'s own office on the away map', function () {
    $clerk = staff(Role::Accounting);
    $clerk->employeeProfile?->forceFill(['office_id' => office(['name' => 'Cebu Branch', 'latitude' => 10.3157, 'longitude' => 123.8854])->id])->save();

    $post = UpdateAwayLocation::postFor(new AwayLog, $clerk->fresh() ?? $clerk);
    expect($post)->toMatchArray(['name' => 'Cebu Branch', 'lat' => 10.3157, 'radius' => 100]);
});

it('refuses to delete an office while staff work there', function () {
    $headOffice = office();
    staff(Role::Accounting)->employeeProfile?->forceFill(['office_id' => $headOffice->id])->save();

    Livewire::actingAs(staff(Role::Admin))->test(Offices::class)
        ->call('delete', $headOffice->id)
        ->assertHasErrors('office');
    expect(Office::query()->whereKey($headOffice->id)->exists())->toBeTrue();
});
