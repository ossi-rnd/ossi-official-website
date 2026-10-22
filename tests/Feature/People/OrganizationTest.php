<?php

use App\Enums\EmploymentStatus;
use App\Enums\Position;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Organization;
use App\Models\Client;
use App\Models\User;
use App\Queries\People\OrganizationQuery;
use App\Support\Navigation;
use Livewire\Livewire;

// The Executive's organization chart: headquarters by role (President and
// Vice President by position), and the field side region → province →
// client → guards.

function named(User $user, string $name): User
{
    $user->forceFill(['name' => $name])->save();
    $user->employeeProfile?->forceFill(['name' => $name])->save(); // the record's name, shown in the guards list

    return $user;
}

beforeEach(function () {
    // The President and Vice President are roles on accounts without an HR record (since 2026-10-21).
    $this->president = named(staff(Role::President), 'Lourdes Reyes');
    $this->executive = $this->president; // the leadership account the access tests use
    $this->vice = named(staff(Role::VicePresident), 'Carlos Tan');
    named(employee(['is_office_staff' => true]), 'Ramon Lacson'); // office staff: not on the Board
    named(staff(Role::SuperAdmin), 'Grace Park');
    named(staff(Role::Hr), 'Erika Bautista');
    named(staff(Role::OperationsHq), 'Teresa Ilagan');
    $this->satellite = named(staff(Role::SatelliteOperations), 'Dennis Ocampo');
    $this->satellite->forceFill(['operations_region' => Region::Calabarzon])->save();

    $this->imus = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $this->calamba = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Laguna', 'name' => 'Metrobank – Calamba']);
    Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);
    named(employee(['client_id' => $this->imus->id, 'post' => 'Main Entrance']), 'Juan Imus');
    named(employee(['client_id' => $this->imus->id, 'post' => 'Parking']), 'Pedro Imus');
    named(employee(['client_id' => $this->imus->id, 'employment_status' => EmploymentStatus::Resigned]), 'Gone Guard'); // not counted
});

it('is for the Executive (and Super Admin), in their menu', function () {
    $this->actingAs($this->executive)->get(route('organization'))->assertOk();
    $this->actingAs(staff(Role::SuperAdmin))->get(route('organization'))->assertOk();
    $this->actingAs(staff(Role::Hr))->get(route('organization'))->assertForbidden();

    $labels = collect(Navigation::for($this->executive))->flatMap(fn (array $section) => array_column($section['items'], 'label'));
    expect($labels)->toContain('Organization');
});

it('fills headquarters from roles, the President and Vice President included', function () {
    $chart = app(OrganizationQuery::class)->handle()['chart'];
    $names = fn (array $box) => $box['people']->pluck('name')->all();

    // The Board: the President and Vice President, by role.
    expect($chart['board']['title'])->toBe('Board of Directors')
        ->and($names($chart['board']))->toBe(['Carlos Tan', 'Lourdes Reyes'])
        ->and($names($chart['president']))->toBe(['Lourdes Reyes'])
        ->and($names($chart['vicePresident']))->toBe(['Carlos Tan'])
        ->and($names($chart['superAdmin']))->toBe(['Grace Park'])
        ->and(array_column($chart['departments'], 'title'))->toBe(['HR', 'Accounting', 'Admin', 'Operation HQ'])
        ->and($names($chart['departments'][0]))->toBe(['Erika Bautista'])
        ->and($chart['departments'][1]['people'])->toBeEmpty()
        ->and($names($chart['satellite']))->toBe(['Dennis Ocampo']);
});

it('builds the field side region → province → client, counting active guards', function () {
    $field = app(OrganizationQuery::class)->handle()['field'];

    expect(array_map(fn (array $row) => $row['region'], $field))->toBe([Region::Ncr, Region::Calabarzon]);

    $calabarzon = $field[1];
    expect($calabarzon['satellites']->pluck('name')->all())->toBe(['Dennis Ocampo'])
        ->and(array_column($calabarzon['provinces'], 'name'))->toBe(['Cavite', 'Laguna'])
        ->and($calabarzon['provinces'][0]['clients'][0]['guards'])->toBe(2) // the resigned one isn't counted
        ->and($calabarzon['clients'])->toBe(2)
        ->and($calabarzon['guards'])->toBe(2);
});

it('shows the chart and lists a client\'s guards on demand', function () {
    Livewire::actingAs($this->executive)->test(Organization::class)
        ->assertSee('Lourdes Reyes')
        ->assertSee('Satellite Operation')
        ->assertSee('Region IV-A')
        ->assertSee('nobody assigned') // NCR has no Satellite officer
        ->call('showClient', $this->imus->id)
        ->assertSee('Metrobank – Imus · 2 guards')
        ->assertSee('Juan Imus')
        ->assertSee('Main Entrance')
        ->assertDontSee('Gone Guard');
});

it('no longer reads the President from a position on an HR record', function () {
    // An old record with the President position, but no President role: not the President.
    $old = named(staff(Role::Hr), 'Old Position Holder');
    $old->employeeProfile?->forceFill(['position' => Position::President])->save();

    $chart = app(OrganizationQuery::class)->handle()['chart'];
    expect($chart['president']['people']->pluck('name')->all())->toBe(['Lourdes Reyes'])
        ->and($chart['board']['people']->pluck('name')->all())->not->toContain('Old Position Holder');
});

it('puts only the President and Vice President on the Board, with no tick on employee records', function () {
    $board = app(OrganizationQuery::class)->handle()['chart']['board']['people']->pluck('name')->all();
    expect($board)->not->toContain('Ramon Lacson')->not->toContain('Grace Park');

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => staff(Role::Accounting)->employeeProfile])
        ->call('edit')
        ->assertDontSee('Member of the Board');
});

it('offers no leadership position: those are roles on accounts without an HR record', function () {
    $offered = array_map(fn (Position $position) => $position->label(), Position::for(true));

    expect(Position::tryFrom('executive'))->toBeNull()
        ->and($offered)->not->toContain('President')->not->toContain('Vice President')
        ->and(Position::President->isOffice())->toBeTrue(); // older records still read as office jobs
});
