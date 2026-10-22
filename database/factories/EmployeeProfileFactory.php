<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Enums\Position;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * By default an UNCLAIMED record: HR added it, nobody has registered yet.
 *
 * @extends Factory<EmployeeProfile>
 */
class EmployeeProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_number' => fake()->unique()->numerify('######').'-'.strtoupper(fake()->lexify('???')).'-'.fake()->numerify('####'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'detachment' => fake()->randomElement(['SM Megamall', 'BGC Tower One', 'Ortigas CBD', 'Makati CBD']),
            'post' => fake()->randomElement(['Main Entrance', 'Parking', 'Lobby', 'Roving']),
            'position' => Position::SecurityGuard,
            'hire_date' => now()->subYear()->toDateString(),
            'employment_status' => EmploymentStatus::Regular,
            'is_office_staff' => false,
        ];
    }

    public function claimedBy(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email]);
    }

    public function officeStaff(): static
    {
        // Office staff aren't deployed to a detachment or post.
        return $this->state(fn () => ['is_office_staff' => true, 'position' => Position::AdminStaff, 'detachment' => null, 'post' => null]);
    }

    public function status(EmploymentStatus $status): static
    {
        return $this->state(fn () => ['employment_status' => $status]);
    }
}
