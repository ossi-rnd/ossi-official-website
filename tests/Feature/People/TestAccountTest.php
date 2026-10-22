<?php

use App\Actions\Auth\ClaimEmployeeAccount;
use App\Enums\Role;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Employees;
use App\Models\EmployeeProfile;
use Livewire\Livewire;

// Pilot testers without a real mailbox: HR ticks "Test account" when adding them,
// the email is made from the employee number at test.invalid, and registering
// confirms it at once (no link to click, no mail sent there).

it('makes a test account\'s email from its employee number', function () {
    Livewire::actingAs(staff(Role::Hr))->test(Employees::class)
        ->call('create')
        ->assertSee('Test account (no real email)')
        ->set('form.is_test_account', true)
        ->assertDontSee('The only email this person can register with.')
        ->assertSee('Shown once the employee number is made')
        ->set('form.hire_date', '2026-06-27')->set('form.birth_province', 'PAM')
        ->assertSee('260627PAM-····@test.invalid') // the province code in capitals, as on the ID card
        ->set('form.name', 'Tester Uno')
        ->call('save')
        ->assertHasNoErrors();

    $profile = EmployeeProfile::query()->where('name', 'Tester Uno')->sole();
    expect($profile->is_test_account)->toBeTrue()
        ->and($profile->email)->toBe($profile->employee_number.'@test.invalid')
        ->and($profile->email)->toContain('PAM');
});

it('confirms a test account on registering and never mails it', function () {
    $profile = EmployeeProfile::factory()->create(['employee_number' => '260627PAM-0950', 'email' => '260627PAM-0950@test.invalid', 'is_test_account' => true]);

    // Typed as HR shows it, in capitals: emails match in any case.
    $user = app(ClaimEmployeeAccount::class)->handle('260627PAM-0950', '260627PAM-0950@test.invalid', 'a-long-password-123');

    // The account keeps the capitals (shown in the header, Roles & Access)...
    expect($user->email)->toBe('260627PAM-0950@test.invalid');

    // ...and they sign in typing it either way.
    $this->post(route('login'), ['email' => '260627pam-0950@TEST.invalid', 'password' => 'a-long-password-123']);
    $this->assertAuthenticatedAs($user);

    // Saving Account settings with the same email in other letters isn't a new email.
    $this->put(route('user-profile-information.update'), ['name' => 'Tester Uno', 'email' => '260627pam-0950@test.invalid']);
    expect($user->fresh()?->email)->toBe('260627PAM-0950@test.invalid')
        ->and($user->fresh()?->hasVerifiedEmail())->toBeTrue();

    // A rehire's -RH is in capitals too.
    expect(EmployeeProfile::testEmailFor('260627pam-0951-rh'))->toBe('260627PAM-0951-RH@test.invalid');

    // Confirmed, so Fortify's Registered listener sends no link; and no mail route at all
    // (MailChannel skips a notifiable without one), so nothing goes to test.invalid.
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->routeNotificationForMail())->toBeNull();

    // A real employee still confirms their email.
    EmployeeProfile::factory()->create(['employee_number' => '260627PAM-0951', 'email' => 'real@example.test']);
    $real = app(ClaimEmployeeAccount::class)->handle('260627PAM-0951', 'real@example.test', 'a-long-password-123');
    expect($real->hasVerifiedEmail())->toBeFalse()
        ->and($real->routeNotificationForMail())->toBe('real@example.test');
    expect($profile->fresh()?->isClaimed())->toBeTrue();
});

it('marks test accounts for HR, and keeps an existing record what it is', function () {
    $profile = EmployeeProfile::factory()->create(['name' => 'Tester Dos', 'employee_number' => '260627PAM-0952', 'email' => '260627PAM-0952@test.invalid', 'is_test_account' => true]);

    Livewire::actingAs(staff(Role::Hr))->test(Employees::class)->assertSee('Tester Dos')->assertSee('Test account');

    Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $profile])
        ->assertSee('Test account')
        ->call('edit')
        ->assertDontSee('Test account (no real email)') // chosen when adding only
        ->assertSee('260627PAM-0952@test.invalid')
        ->set('form.is_test_account', false) // ignored on an existing record
        ->set('form.name', 'Tester Dos Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($profile->fresh()?->is_test_account)->toBeTrue()
        ->and($profile->fresh()?->email)->toBe('260627PAM-0952@test.invalid');
});
