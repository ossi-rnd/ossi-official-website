<?php

use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\Clients;
use App\Livewire\Admin\EmployeeDetail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Support\Navigation;
use Livewire\Livewire;

function client(Region $region = Region::Calabarzon, string $province = 'Cavite', string $name = 'Metrobank – Imus'): Client
{
    return Client::query()->create(['region' => $region, 'province' => $province, 'name' => $name]);
}

it('lets the Super Admin add a client under Region → Province → Client', function () {
    Livewire::actingAs(staff(Role::SuperAdmin))->test(Clients::class)
        ->call('create')
        ->set('region', Region::Calabarzon->value)
        ->set('province', 'Cavite')
        ->set('name', 'Metrobank')
        ->set('site_address', 'Aguinaldo Hwy., Imus')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('tab', Region::Calabarzon->value)
        ->assertSee('Region IV-A · CALABARZON')
        ->assertSee('Cavite')
        ->assertSee('Metrobank');

    expect(Client::query()->sole())
        ->region->toBe(Region::Calabarzon)
        ->province->toBe('Cavite')
        ->locationLabel()->toBe('Cavite, Region IV-A')
        ->and(ActivityLog::query()->where('event', 'client.created')->exists())->toBeTrue();
});

it('lets Operation HQ and Admin set up clients before HR onboards guards, but not Satellite Operation or HR', function () {
    $hq = staff(Role::OperationsHq);

    Livewire::actingAs($hq)->test(Clients::class)
        ->call('create')
        ->set('region', Region::Ncr->value)->set('province', 'Mandaluyong')->set('name', 'SM Megamall')
        ->call('save')
        ->assertHasNoErrors();
    expect(Client::query()->where('name', 'SM Megamall')->exists())->toBeTrue();

    // In Operation HQ's menu with the other Operations pages.
    $management = collect(Navigation::for($hq))->firstWhere('title', 'Management');
    expect(array_column($management['items'] ?? [], 'label'))->toContain('Clients');

    // Admin can too.
    Livewire::actingAs(staff(Role::Admin))->test(Clients::class)
        ->call('create')
        ->set('region', Region::Ncr->value)->set('province', 'Makati')->set('name', 'Ayala Tower One')
        ->call('save')
        ->assertHasNoErrors();
    expect(Client::query()->where('name', 'Ayala Tower One')->exists())->toBeTrue();

    // Satellite Operation (below HQ) and HR: the page is closed.
    foreach ([Role::SatelliteOperations, Role::Hr] as $role) {
        $this->actingAs(staff($role))->get(route('clients'))->assertForbidden();
        Livewire::actingAs(staff($role))->test(Clients::class)->call('create')->assertForbidden();
    }
});

it('only accepts a province of the chosen region, and clears it when the region changes', function () {
    Livewire::actingAs(staff(Role::SuperAdmin))->test(Clients::class)
        ->call('create')
        ->set('region', Region::Calabarzon->value)
        ->set('province', 'Cebu')
        ->set('name', 'Metrobank')
        ->call('save')
        ->assertHasErrors('province')
        ->set('province', 'Cavite')
        ->set('region', Region::CentralVisayas->value)
        ->assertSet('province', '');

    expect(Client::query()->count())->toBe(0);
});

it('refuses the same client twice in one province', function () {
    client();

    Livewire::actingAs(staff(Role::SuperAdmin))->test(Clients::class)
        ->call('create')
        ->set('region', Region::Calabarzon->value)->set('province', 'Cavite')->set('name', 'Metrobank – Imus')
        ->call('save')
        ->assertHasErrors('name');
});

it('shows a tab only for regions that have clients, and filters by it', function () {
    client();
    client(Region::Ncr, 'Makati', 'Makati CBD');

    Livewire::actingAs(staff(Role::SuperAdmin))->test(Clients::class)
        ->assertSee('All regions')->assertSee('Region IV-A')->assertSee('NCR')->assertDontSee('BARMM')
        ->call('selectTab', Region::Ncr->value)
        ->assertSee('Makati CBD')->assertDontSee('Metrobank – Imus');
});

it('lets HR deploy a guard to a client, and a rename follows them', function () {
    $metrobank = client();
    $profile = employee()->employeeProfile;

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->assertSee('Metrobank – Imus · Cavite, Region IV-A')
        ->set('form.client_id', (string) $metrobank->id)
        ->set('form.post', 'Vault')
        ->call('save')
        ->assertHasNoErrors();

    expect($profile?->fresh())->client_id->toBe($metrobank->id)->detachment->toBe('Metrobank – Imus');

    Livewire::actingAs(staff(Role::SuperAdmin))->test(Clients::class)
        ->call('edit', $metrobank->id)->set('name', 'Metrobank – Imus Branch')->call('save')->assertHasNoErrors();

    expect($profile?->fresh()?->detachment)->toBe('Metrobank – Imus Branch');
});

it('keeps a detachment typed before clients existed until HR picks the client', function () {
    $profile = employee(['detachment' => 'Old Site', 'post' => 'Gate'])->employeeProfile;

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->call('edit')
        ->assertSee('typed before clients were set up')
        ->set('form.contact_number', '0917 000 0000')
        ->call('save')
        ->assertHasNoErrors();

    expect($profile?->fresh())->detachment->toBe('Old Site')->client_id->toBeNull();
});

it('will not delete a client guards are deployed to', function () {
    $metrobank = client();
    employee()->employeeProfile?->forceFill(['client_id' => $metrobank->id, 'detachment' => $metrobank->name])->save();

    Livewire::actingAs(staff(Role::SuperAdmin))->test(Clients::class)
        ->call('delete', $metrobank->id)
        ->assertHasErrors('client');

    expect($metrobank->fresh())->not->toBeNull();
});

it('lists the guards deployed to a client', function () {
    $metrobank = client();
    $other = client(Region::Ncr, 'Makati', 'Makati CBD');

    foreach ([['Rico Imus', $metrobank, 'Vault'], ['Lito Imus', $metrobank, 'Gate'], ['Makati Guard', $other, 'Lobby']] as [$name, $site, $post]) {
        employee(['name' => $name, 'client_id' => $site->id, 'detachment' => $site->name, 'post' => $post]);
    }

    Livewire::actingAs(staff(Role::SuperAdmin))->test(Clients::class)
        ->assertSee('2 guards')
        ->call('viewGuards', $metrobank->id)
        ->assertSee('Rico Imus')->assertSee('Vault')->assertSee('Lito Imus')
        ->assertDontSee('Makati Guard')
        ->assertSee('2 guards deployed')
        ->call('closeGuards')
        ->assertDontSee('Rico Imus');
});

it('keeps clients to the Super Admin', function () {
    $this->actingAs(staff(Role::Hr))->get(route('clients'))->assertForbidden();
    Livewire::actingAs(staff(Role::Hr))->test(Clients::class)->call('create')->assertForbidden();
});
