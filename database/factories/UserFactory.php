<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            // Most tests are about something else, so the notice is already acknowledged.
            'privacy_notice_version' => config('privacy.version'),
            'privacy_acknowledged_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function privacyPending(): static
    {
        return $this->state(fn () => ['privacy_notice_version' => null, 'privacy_acknowledged_at' => null]);
    }

    public function withRoles(Role ...$roles): static
    {
        return $this->afterCreating(function (User $user) use ($roles) {
            foreach ($roles as $role) {
                $user->roles()->create(['role' => $role]);
            }
        });
    }

    /**
     * An employee with an HR profile, i.e. someone on payroll.
     *
     * @param  array<string, mixed>  $profile
     */
    public function onPayroll(array $profile = []): static
    {
        return $this->afterCreating(function (User $user) use ($profile) {
            EmployeeProfile::factory()->claimedBy($user)->create($profile);
        });
    }
}
