<?php

use App\Actions\Auth\ClaimEmployeeAccount;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\RolesAccess;
use App\Models\EmployeeProfile;
use Livewire\Livewire;

// Someone HR added who hasn't registered has no account, so no roles yet. The Super Admin
// sets their role in advance on Roles & Access; it's given to the account on registering.

beforeEach(function () {
    $this->superAdmin = staff(Role::SuperAdmin);
    $this->profile = EmployeeProfile::factory()->create(['name' => 'Ana Admin', 'email' => 'ana@example.test', 'is_office_staff' => true, 'user_id' => null]);
});

it('lists people who haven\'t registered and sets their role in advance', function () {
    Livewire::actingAs($this->superAdmin)->test(RolesAccess::class)
        ->assertSee('Not registered yet')->assertSee('Ana Admin')
        ->call('editPending', $this->profile->id)
        ->set('jobRole', Role::Admin->value)->call('savePending')->assertHasNoErrors()
        ->assertSee('Admin');

    expect($this->profile->fresh()?->pending_role)->toBe(Role::Admin);
});

it('gives the role to the account when they register', function () {
    $this->profile->forceFill(['pending_role' => Role::SatelliteOperations, 'pending_region' => Region::Ncr])->save();

    $user = app(ClaimEmployeeAccount::class)->handle($this->profile->employee_number, 'ana@example.test', 'Str0ng!Password');

    expect($user->roleList())->toEqualCanonicalizing([Role::Employee, Role::SatelliteOperations])
        ->and($user->operations_region)->toBe(Region::Ncr)
        ->and($this->profile->fresh()?->pending_role)->toBeNull();
});

it('keeps Super Admin, President and VP off records, and needs a Satellite\'s region', function () {
    $page = Livewire::actingAs($this->superAdmin)->test(RolesAccess::class)->call('editPending', $this->profile->id);

    $page->set('jobRole', Role::SuperAdmin->value)->call('savePending')->assertHasErrors('jobRole');
    $page->set('jobRole', Role::SatelliteOperations->value)->set('region', '')->call('savePending')->assertHasErrors('region');

    Livewire::actingAs(staff(Role::Hr))->test(RolesAccess::class)->call('editPending', $this->profile->id)->assertForbidden();
});
