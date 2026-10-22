<?php

use App\Actions\Roles\SyncUserRoles;
use App\Enums\Ability;
use App\Enums\Role;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Validation\ValidationException;

// The company doesn't use the word "Executive": the role is two, President and Vice
// President, with exactly the same access (Role::leadership()), so either can act.

it('gives the President and the Vice President exactly the same access', function () {
    foreach (Ability::cases() as $ability) {
        $roles = $ability->roles();

        expect(in_array(Role::President, $roles, true))
            ->toBe(in_array(Role::VicePresident, $roles, true), "{$ability->value}: President and Vice President differ");
    }

    $president = staff(Role::President);
    $vice = staff(Role::VicePresident);
    $menu = fn ($user) => collect(Navigation::for($user))->flatMap(fn (array $section) => array_column($section['items'], 'label'))->all();

    expect($menu($vice))->toBe($menu($president))
        ->and($vice->can(Ability::ApproveReimbursements->value))->toBeTrue()
        ->and($vice->can(Ability::ReviewOfficeLeave->value))->toBeTrue();
});

it('creates Super Admin, President and Vice President accounts without an employee record', function () {
    foreach (['super-admin' => 'sa@company.test', 'president' => 'pres@company.test', 'vice-president' => 'vp@company.test'] as $role => $email) {
        $this->artisan('app:create-super-admin', ['email' => $email, 'name' => 'Leader '.$role, '--role' => $role])
            ->expectsQuestion('Password (12+ characters, mixed case, numbers, symbols)', 'Ossi-Pilot#2026x')
            ->assertSuccessful();

        $user = User::query()->where('email', $email)->sole();
        expect($user->roleList())->toBe([Role::from($role)])
            ->and($user->employeeProfile)->toBeNull()
            ->and($user->isPaidOutsidePayroll())->toBeTrue();
    }

    // Not for ordinary roles: those are added by HR and register.
    $this->artisan('app:create-super-admin', ['email' => 'hr@company.test', 'name' => 'HR', '--role' => 'hr'])->assertFailed();
});

it('refuses to give Super Admin, President or Vice President to someone with an employee record', function () {
    $clerk = staff(Role::Hr);

    foreach ([Role::SuperAdmin, Role::President, Role::VicePresident] as $role) {
        expect(fn () => app(SyncUserRoles::class)->handle($clerk, [Role::Hr, $role], staff(Role::SuperAdmin)))
            ->toThrow(ValidationException::class, "{$role->label()} can't go to someone with an employee record");
    }

    expect($clerk->fresh()?->roleList())->toBe([Role::Hr]); // unchanged
});

it('keeps these accounts out of payroll, attendance and headcount', function () {
    $admin = staff(Role::SuperAdmin);

    expect($admin->employeeProfile)->toBeNull()
        ->and(User::query()->onPayroll()->whereKey($admin->id)->exists())->toBeFalse();
    $this->actingAs($admin)->get(route('attendance'))->assertForbidden();
    $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    // My Profile says so plainly, not "HR hasn't set up your employee record yet".
    $this->actingAs($admin)->get(route('profile'))->assertOk()
        ->assertSee('This is a Super Admin account, not an employee')
        ->assertSee('It can still open and manage every part of the system.')
        ->assertDontSee('set up your employee record yet');
    $this->actingAs(staff(Role::President))->get(route('profile'))
        ->assertSee('This is a President account, not an employee')
        ->assertSee('It still has all its President access and approvals.');
});

it('never shows the word "Executive" on the roles page or a leadership dashboard', function () {
    $this->actingAs(staff(Role::SuperAdmin))->get(route('roles'))->assertOk()
        ->assertSee('President')->assertSee('Vice President')->assertDontSee('Executive');

    $this->actingAs(staff(Role::VicePresident))->get(route('dashboard'))->assertOk()->assertDontSee('Executive');
});
