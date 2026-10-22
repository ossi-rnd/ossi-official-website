<?php

use App\Enums\PunchType;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\AttendanceLog;
use App\Livewire\Admin\Employees;
use App\Models\Client;
use App\Models\TimeLog;
use App\Models\User;
use Livewire\Livewire;

// Employees and Attendance Log: Region → Province → Client, by where people
// are deployed (not their home address); "Office staff" for those with no client.

beforeEach(function () {
    $this->hr = staff(Role::Hr);
    $this->hr->forceFill(['name' => 'Hannah Office'])->save();
    $this->hr->employeeProfile?->forceFill(['name' => 'Hannah Office'])->save();

    $imus = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $calamba = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Laguna', 'name' => 'Metrobank – Calamba']);
    $megamall = Client::query()->create(['region' => Region::Ncr, 'province' => 'Mandaluyong', 'name' => 'SM Megamall']);

    $guard = function (string $name, Client $client): User {
        $user = employee(['client_id' => $client->id, 'name' => $name]);
        $user->forceFill(['name' => $name])->save();
        $log = TimeLog::factory()->for($user)->create();
        $log->punches()->forceCreate(['type' => PunchType::ClockIn, 'punched_at' => $log->clock_in_at, 'photo_path' => 'punches/x.jpg', 'latitude' => 14.4, 'longitude' => 120.9]);

        return $user;
    };
    $guard('Ivan Imus', $imus);
    $guard('Carla Calamba', $calamba);
    $guard('Mega Mario', $megamall);
});

it('filters the employee list by region, then province, then client', function () {
    $page = Livewire::actingAs($this->hr)->test(Employees::class)
        ->assertSee('Ivan Imus')->assertSee('Mega Mario')->assertSee('Hannah Office');

    expect($page->instance()->areaRegions())->toBe([Region::Ncr, Region::Calabarzon]);

    $page->set('areaRegion', Region::Calabarzon->value)
        ->assertSee('Ivan Imus')->assertSee('Carla Calamba')->assertDontSee('Mega Mario')->assertDontSee('Hannah Office');
    expect($page->instance()->areaProvinces())->toBe(['Cavite', 'Laguna']);

    $page->set('areaProvince', 'Laguna')->assertSee('Carla Calamba')->assertDontSee('Ivan Imus');
    expect($page->instance()->areaClients()->pluck('name')->all())->toBe(['Metrobank – Calamba']);

    // A new region clears the province and client picked under the old one.
    $page->set('areaClient', (string) $page->instance()->areaClients()->first()?->id)
        ->set('areaRegion', Region::Ncr->value)
        ->assertSet('areaProvince', '')->assertSet('areaClient', '')
        ->assertSee('Mega Mario')->assertDontSee('Carla Calamba');

    $page->set('areaRegion', Employees::OFFICE)->assertSee('Hannah Office')->assertDontSee('Mega Mario');
    $page->call('clearArea')->assertSee('Mega Mario')->assertSee('Hannah Office');
});

it('filters the attendance log the same way, by the person\'s client', function () {
    Livewire::actingAs($this->hr)->test(AttendanceLog::class)
        ->assertSee('Ivan Imus')->assertSee('Mega Mario')
        ->set('areaRegion', Region::Calabarzon->value)->set('areaProvince', 'Cavite')
        ->assertSee('Ivan Imus')->assertDontSee('Carla Calamba')->assertDontSee('Mega Mario');
});

it('offers a Satellite Operation only its own region', function () {
    $satellite = staff(Role::SatelliteOperations);
    $satellite->forceFill(['operations_region' => Region::Calabarzon])->save();

    $page = Livewire::actingAs($satellite)->test(AttendanceLog::class)->assertDontSee('Mega Mario');
    expect($page->instance()->areaRegions())->toBe([Region::Calabarzon]);
});
