<?php

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Support\Facades\Notification;

it('shows the login page', function () {
    $this->get(route('login'))->assertOk()->assertSee('Welcome back');
});

it('signs in and sends employees to My Attendance, employers to the dashboard', function (Role $role, string $destination) {
    $user = employee([], $role);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect('/home');
    $this->get('/home')->assertRedirect(route($destination));
})->with([
    'employee' => [Role::Employee, 'attendance'],
    'admin' => [Role::Admin, 'dashboard'],
    // HR and Accounting have their own dashboard sections now (their to-do, people, payroll).
    'hr' => [Role::Hr, 'dashboard'],
    'accounting' => [Role::Accounting, 'dashboard'],
    'developer' => [Role::Developer, 'attendance'],
]);

it('rejects a wrong password', function () {
    $user = employee();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('refuses resigned or terminated employees even with the right password', function () {
    $user = employee(['employment_status' => EmploymentStatus::Terminated]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account has been deactivated. Please contact HR.']);
    $this->assertGuest();
});

it('locks one email out after 5 attempts a minute', function () {
    $user = employee();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
});

it('blocks one IP trying many emails, but lets a shift change through', function () {
    // Guards on one mobile network or site Wi-Fi share an address: 21 sign-ins a minute is normal.
    foreach (range(1, 20) as $attempt) {
        $this->post(route('login.store'), ['email' => "person{$attempt}@example.test", 'password' => 'wrong']);
    }
    $guard = employee();
    $this->post(route('login.store'), ['email' => $guard->email, 'password' => 'password'])->assertRedirect();
    auth()->logout();

    foreach (range(22, 60) as $attempt) {
        $this->post(route('login.store'), ['email' => "person{$attempt}@example.test", 'password' => 'wrong']);
    }
    $this->post(route('login.store'), ['email' => 'person61@example.test', 'password' => 'wrong'])->assertTooManyRequests();
});

it('sends unverified users to the verify-email page', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('attendance'))
        ->assertRedirect(route('verification.notice'));
});

it('emails a password reset link through the queue', function () {
    Notification::fake();
    $user = employee();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');

    Notification::assertSentTo($user, QueuedResetPassword::class);
});

it('logs out and returns to the login page', function () {
    $this->actingAs(employee())->post(route('logout'))->assertRedirect('/login');
    $this->assertGuest();
});
