<?php

namespace Database\Factories;

use App\Enums\TimeLogStatus;
use App\Models\TimeLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A completed on-time day, 09:00–18:00. Tests override the minutes they care about.
 *
 * @extends Factory<TimeLog>
 */
class TimeLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = CarbonImmutable::today()->subDay();

        return [
            'log_date' => $date->toDateString(),
            'clock_in_at' => $date->setTime(9, 0),
            'clock_out_at' => $date->setTime(18, 0),
            'status' => TimeLogStatus::OnTime,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
        ];
    }

    public function on(CarbonImmutable $date): static
    {
        return $this->state(fn () => [
            'log_date' => $date->toDateString(),
            'clock_in_at' => $date->setTime(9, 0),
            'clock_out_at' => $date->setTime(18, 0),
        ]);
    }
}
