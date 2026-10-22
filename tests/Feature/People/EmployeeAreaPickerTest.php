<?php

use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Employees;
use App\Models\Client;
use App\Models\EmployeeProfile;
use Livewire\Livewire;

// Add / Edit employee: Region → Province narrow the Detachment list, and
// picking a detachment fills them in. Nothing new is stored: the client
// carries its region and province.

beforeEach(function () {
    $this->hr = staff(Role::Hr);
    $this->imus = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $this->calamba = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Laguna', 'name' => 'Metrobank – Calamba']);
    $this->megamall = Client::query()->create(['region' => Region::Ncr, 'province' => 'Mandaluyong', 'name' => 'SM Megamall']);
});

it('narrows the detachments by region, then province', function () {
    $page = Livewire::actingAs($this->hr)->test(Employees::class)->call('create');

    expect($page->instance()->form->regionsWithClients())->toBe([Region::Ncr, Region::Calabarzon])
        // By region, then province (Cavite before Laguna), then name.
        ->and($page->instance()->form->clientsInArea()->pluck('name')->all())->toBe(['SM Megamall', 'Metrobank – Imus', 'Metrobank – Calamba']);

    $page->set('form.region', Region::Calabarzon->value);
    expect($page->instance()->form->provincesInRegion())->toBe(['Cavite', 'Laguna'])
        ->and($page->instance()->form->clientsInArea()->pluck('name')->all())->toBe(['Metrobank – Imus', 'Metrobank – Calamba']);

    $page->set('form.province', 'Cavite');
    expect($page->instance()->form->clientsInArea()->pluck('name')->all())->toBe(['Metrobank – Imus']);
});

it('drops a detachment outside the chosen area, and clears the province when the region changes', function () {
    Livewire::actingAs($this->hr)->test(Employees::class)->call('create')
        ->set('form.client_id', (string) $this->imus->id)
        ->assertSet('form.region', Region::Calabarzon->value) // filled in from the client
        ->assertSet('form.province', 'Cavite')
        ->set('form.province', 'Laguna')
        ->assertSet('form.client_id', '') // Imus isn't in Laguna
        ->set('form.region', Region::Ncr->value)
        ->assertSet('form.province', '');
});

it('saves the detachment picked through the area, with a rehire number', function () {
    Livewire::actingAs($this->hr)->test(Employees::class)->call('create')
        ->call('useNumberFrom', 'card')->set('form.employee_number', '230915CAV-7034-RH')
        ->set('form.name', 'Rodel Rehire')
        ->set('form.email', 'rodel@example.test')
        ->set('form.region', Region::Calabarzon->value)
        ->set('form.province', 'Laguna')
        ->set('form.client_id', (string) $this->calamba->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(EmployeeProfile::query()->where('email', 'rodel@example.test')->sole())
        ->client_id->toBe($this->calamba->id)
        ->detachment->toBe('Metrobank – Calamba')
        ->employee_number->toBe('230915CAV-7034-RH')
        ->and(EmployeeProfile::query()->where('email', 'rodel@example.test')->sole()->hire_date?->toDateString())->toBe('2023-09-15');
});

it('opens an existing guard\'s record on their detachment\'s area', function () {
    $guard = employee(['client_id' => $this->imus->id, 'detachment' => $this->imus->name]);

    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['profile' => $guard->employeeProfile()->firstOrFail()])
        ->call('edit')
        ->assertSet('form.region', Region::Calabarzon->value)
        ->assertSet('form.province', 'Cavite')
        ->assertSet('form.client_id', (string) $this->imus->id);
});
