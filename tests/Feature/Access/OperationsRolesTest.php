<?php

use App\Actions\People\DeployEmployee;
use App\Actions\Roles\SyncUserRoles;
use App\Enums\Region;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Employees;
use App\Livewire\Admin\LeaveRequests;
use App\Livewire\Admin\OvertimeRequests;
use App\Livewire\Admin\RolesAccess;
use App\Models\Client;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function leaveOf(User $user): LeaveRequest
{
    $leave = new LeaveRequest(['type' => 'vacation', 'start_date' => today()->addDays(3), 'end_date' => today()->addDays(3), 'reason' => 'Family event']);
    $leave->user()->associate($user)->save();

    return $leave;
}

function overtimeOf(User $user, User $supervisor): OvertimeRequest
{
    $overtime = new OvertimeRequest(['date' => today()->subDay(), 'requested_minutes' => 120, 'reason' => 'Relief late']);
    $overtime->user()->associate($user);
    $overtime->supervisor()->associate($supervisor);
    $overtime->save();

    return $overtime;
}

/** A guard deployed to a client in $region. */
function guardIn(Region $region, string $province, string $client, string $name): User
{
    $site = Client::query()->firstOrCreate(['region' => $region->value, 'province' => $province, 'name' => $client]);

    $guard = employee(['name' => $name, 'client_id' => $site->id, 'detachment' => $site->name]);
    $guard->forceFill(['name' => $name])->save();

    return $guard;
}

function satellite(Region $region): User
{
    $user = staff(Role::SatelliteOperations);
    $user->forceFill(['operations_region' => $region])->save();

    return $user;
}

describe('leave approvers', function () {
    it('has HR approve guards\' leave, but not office staff\'s', function () {
        $hr = staff(Role::Hr);
        $guardLeave = leaveOf(employee());
        $officeLeave = leaveOf(staff(Role::Accounting));

        // Office staff's leave goes to the Executive only: HR doesn't even see it.
        Livewire::actingAs($hr)->test(LeaveRequests::class)
            ->assertSee($guardLeave->user->name)->assertDontSee($officeLeave->user->name)
            ->call('startReview', $guardLeave->id)->call('approve')->assertHasNoErrors()
            ->call('startReview', $officeLeave->id)->assertNotFound();

        expect($guardLeave->fresh()?->status)->toBe(RequestStatus::Approved)
            ->and($officeLeave->fresh()?->status)->toBe(RequestStatus::Pending);
    });

    it('has the Executive approve office staff\'s leave, but not guards\'', function () {
        $executive = staff(Role::President);
        $officeLeave = leaveOf(staff(Role::Hr));
        $guardLeave = leaveOf(employee());

        // Two tabs: office staff's (theirs) first; guards' only to watch.
        Livewire::actingAs($executive)->test(LeaveRequests::class)
            ->assertSee('yours to decide')->assertSee($officeLeave->user->name)->assertDontSee($guardLeave->user->name)
            ->set('group', 'field')->assertSee($guardLeave->user->name)->assertSee('View only')
            ->set('group', 'office')
            ->call('startReview', $officeLeave->id)->call('approve')->assertHasNoErrors()
            ->set('reviewingId', $guardLeave->id)->call('approve')->assertHasErrors('review');

        expect($officeLeave->fresh()?->status)->toBe(RequestStatus::Approved)
            ->and($guardLeave->fresh()?->status)->toBe(RequestStatus::Pending);
    });

    it('lets Operation HQ see leave requests but decide none', function () {
        $rico = employee();
        $rico->forceFill(['name' => 'Rico Guard'])->save();
        leaveOf($rico);

        Livewire::actingAs(staff(Role::OperationsHq))->test(LeaveRequests::class)
            ->assertSee('Rico Guard')
            ->assertDontSee('yours to decide') // only guards' requests: no tabs
            ->assertDontSeeHtml('wire:click="startReview');
    });
});

describe('overtime approvers', function () {
    it('lets Operation HQ approve guards\' overtime, while the Executive only monitors it', function () {
        $opsHq = staff(Role::OperationsHq);
        $overtime = overtimeOf(employee(), $opsHq);

        Livewire::actingAs(staff(Role::President))->test(OvertimeRequests::class)
            ->set('group', 'field')->assertSee('View only')
            ->call('startReview', $overtime->id)->assertHasErrors('list');

        Livewire::actingAs($opsHq)->test(OvertimeRequests::class)
            ->call('startReview', $overtime->id)->call('approve')->assertHasNoErrors();

        expect($overtime->fresh()?->status)->toBe(RequestStatus::Approved);
    });
});

describe('Satellite Operation', function () {
    beforeEach(function () {
        $this->caviteGuard = guardIn(Region::Calabarzon, 'Cavite', 'Metrobank – Imus', 'Cavite Guard');
        $this->cebuGuard = guardIn(Region::CentralVisayas, 'Cebu', 'Ayala Center Cebu', 'Cebu Guard');
        $this->satellite = satellite(Region::Calabarzon);
    });

    it('sees only guards deployed in its region', function () {
        Livewire::actingAs($this->satellite)->test(Employees::class)
            ->assertSee('Cavite Guard')->assertDontSee('Cebu Guard');

        $this->actingAs($this->satellite)->get(route('employees.show', $this->caviteGuard->employeeProfile))->assertOk();
        $this->actingAs($this->satellite)->get(route('employees.show', $this->cebuGuard->employeeProfile))->assertForbidden();
    });

    it('sees and approves overtime only in its region', function () {
        $inRegion = overtimeOf($this->caviteGuard, $this->satellite);
        $outside = overtimeOf($this->cebuGuard, $this->satellite);

        Livewire::actingAs($this->satellite)->test(OvertimeRequests::class)
            ->assertSee('Cavite Guard')->assertDontSee('Cebu Guard')
            ->call('startReview', $inRegion->id)->call('approve')->assertHasNoErrors()
            // Forced past the list: outside the region it isn't even found.
            ->set('reviewingId', $outside->id)->set('approvedHours', '1')->call('approve')->assertNotFound();

        expect($outside->fresh()?->status)->toBe(RequestStatus::Pending);
    });

    it('moves a guard to another client and post in its region, without full edit access', function () {
        $dasma = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Dasmariñas']);
        $profile = $this->caviteGuard->employeeProfile;

        Livewire::actingAs($this->satellite)->test(EmployeeDetail::class, ['profile' => $profile])
            ->assertDontSee('Edit record')
            ->assertDontSee('Conditions and allergies')
            ->call('startDeployment')
            ->assertSee('Metrobank – Dasmariñas')->assertDontSee('Ayala Center Cebu')
            ->set('deployClientId', (string) $dasma->id)
            ->set('deployPost', 'Vault')
            ->call('saveDeployment')
            ->assertHasNoErrors();

        expect($profile?->fresh())->client_id->toBe($dasma->id)->detachment->toBe('Metrobank – Dasmariñas')->post->toBe('Vault');

        Livewire::actingAs($this->satellite)->test(EmployeeDetail::class, ['profile' => $profile])->call('edit')->assertForbidden();
    });

    it('cannot deploy to a client, or move a guard, outside its region', function () {
        $cebu = Client::query()->where('name', 'Ayala Center Cebu')->sole();

        expect(fn () => app(DeployEmployee::class)->handle($this->caviteGuard->employeeProfile, $cebu, null, null, $this->satellite))
            ->toThrow(ValidationException::class, 'Choose a client in your region.')
            ->and(fn () => app(DeployEmployee::class)->handle($this->cebuGuard->employeeProfile, $cebu, null, null, $this->satellite))
            ->toThrow(AuthorizationException::class);
    });

    it('sees no one until a region is set', function () {
        $unassigned = staff(Role::SatelliteOperations);

        Livewire::actingAs($unassigned)->test(Employees::class)->assertDontSee('Cavite Guard')->assertDontSee('Cebu Guard');
    });
});

it('requires a region for a Satellite Operation, and clears it with the role', function () {
    $superAdmin = staff(Role::SuperAdmin);
    $target = employee();

    Livewire::actingAs($superAdmin)->test(RolesAccess::class)
        ->call('edit', $target->id)
        ->set('jobRole', 'satellite-operations')
        ->assertSee('Region covered')
        ->call('save')->assertHasErrors('region')
        ->set('region', Region::Calabarzon->value)
        ->call('save')->assertHasNoErrors();

    expect($target->fresh())->operations_region->toBe(Region::Calabarzon)
        ->and($target->fresh()?->hasRole(Role::SatelliteOperations))->toBeTrue();

    app(SyncUserRoles::class)->handle($target->fresh() ?? $target, [Role::Employee], $superAdmin);

    expect($target->fresh()?->operations_region)->toBeNull();
});

it('lets viewers see the employee list without HR\'s editing or personal details', function () {
    $guard = employee(['name' => 'Visible Guard', 'medical_conditions' => 'Asthma']);

    Livewire::actingAs(staff(Role::President))->test(Employees::class)
        ->assertSee('Visible Guard')->assertDontSee('Add employee')
        ->call('create')->assertForbidden();

    $this->actingAs(staff(Role::President))->get(route('employees.show', $guard->employeeProfile))
        ->assertOk()->assertDontSee('Asthma')->assertDontSee('Edit record')->assertSee('Pay rate');

    $this->actingAs(staff(Role::Admin))->get(route('employees.show', $guard->employeeProfile))
        ->assertOk()->assertDontSee('Asthma')->assertDontSee('Pay rate');
});
