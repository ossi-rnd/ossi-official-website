<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Shift '.fake()->unique()->numerify('###'),
            'start_time' => '09:00',
            'end_time' => '18:00',
            'grace_minutes' => 10,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['name' => 'Day Shift', 'is_default' => true]);
    }

    public function night(): static
    {
        return $this->state(fn () => ['name' => 'Night Shift', 'start_time' => '22:00', 'end_time' => '06:00']);
    }
}
