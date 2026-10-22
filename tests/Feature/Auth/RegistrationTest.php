<?php

use App\Actions\Auth\ClaimEmployeeAccount;
use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Support\Facades\Notification;

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function registration(array $overrides = []): array
{
    return [
        'employee_number' => 'EMP-3001',
        'email' => 'juan@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ];
}

beforeEach(function () {
    // HR has added Juan; he hasn't registered yet.
    EmployeeProfile::factory()->create(['employee_number' => 'EMP-3001', 'email' => 'juan@example.test', 'name' => 'Juan Dela Cruz']);
});

it('lets an employee HR registered create an account', function () {
    Notification::fake();

    $this->post(route('register.store'), registration())->assertRedirect('/home');

    $user = User::query()->where('email', 'juan@example.test')->firstOrFail();

    expect($user->name)->toBe('Juan Dela Cruz') // HR's name is authoritative, not typed by the user
        ->and($user->hasRole(Role::Employee))->toBeTrue()
        ->and(EmployeeProfile::query()->where('employee_number', 'EMP-3001')->value('user_id'))->toBe($user->id)
        ->and(ActivityLog::query()->where('event', 'registration.succeeded')->where('causer_id', $user->id)->exists())->toBeTrue();

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, QueuedVerifyEmail::class);
});

it('matches the email case-insensitively', function () {
    $this->post(route('register.store'), registration(['email' => 'Juan@Example.TEST']))->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'juan@example.test')->exists())->toBeTrue();
});

it('refuses anyone HR has not registered, with one generic message, and logs why', function (array $input, string $reason) {
    EmployeeProfile::factory()->claimedBy(User::factory()->create(['email' => 'taken@example.test']))->create(['employee_number' => 'EMP-CLAIMED']);
    EmployeeProfile::factory()->status(EmploymentStatus::Terminated)->create(['employee_number' => 'EMP-GONE', 'email' => 'gone@example.test']);

    $this->post(route('register.store'), registration($input))
        ->assertSessionHasErrors(['employee_number' => ClaimEmployeeAccount::FAILURE_MESSAGE]);

    $this->assertGuest();

    $entry = ActivityLog::query()->where('event', 'registration.failed')->sole();
    expect($entry->properties['reason'] ?? null)->toBe($reason);
})->with([
    'unknown employee number' => [['employee_number' => 'EMP-9999'], 'unknown_employee_number'],
    'email does not match HR record' => [['email' => 'someone@else.test'], 'email_mismatch'],
    'record already claimed' => [['employee_number' => 'EMP-CLAIMED', 'email' => 'taken@example.test'], 'already_claimed'],
    'employee terminated' => [['employee_number' => 'EMP-GONE', 'email' => 'gone@example.test'], 'not_active'],
]);

it('refuses when another account already uses the email', function () {
    User::factory()->create(['email' => 'juan@example.test']);

    $this->post(route('register.store'), registration())->assertSessionHasErrors('employee_number');

    expect(ActivityLog::query()->where('event', 'registration.failed')->sole()->properties['reason'] ?? null)->toBe('email_taken');
});

it('rate limits registration attempts from one IP', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('register.store'), registration(['employee_number' => "EMP-GUESS-{$attempt}"]));
    }

    // Even correct details are refused until the window passes.
    $this->post(route('register.store'), registration())
        ->assertSessionHasErrors(['employee_number' => 'Too many registration attempts. Please try again in 15 minute(s).']);

    expect(User::query()->where('email', 'juan@example.test')->exists())->toBeFalse();
});

it('validates the password', function () {
    $this->post(route('register.store'), registration(['password_confirmation' => 'different']))->assertSessionHasErrors('password');
});
