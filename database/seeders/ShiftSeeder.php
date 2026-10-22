<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * Reference data, run in production too: the default shift and the duty
 * templates the Duty Roster picks from. Duties are 9 hours: 8 hours of work
 * plus the 1-hour meal break (Labor Code Arts. 83 and 85).
 */
class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{name: string, start_time: string, end_time: string, grace_minutes: int} $default */
        $default = config('attendance.default_shift');

        Shift::query()->updateOrCreate(['name' => $default['name']], [...$default, 'is_default' => true]);
        Shift::query()->updateOrCreate(['name' => 'Night Shift'], ['start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => 10, 'is_default' => false]);

        foreach ([
            ['Day Duty', '06:00', '15:00'],
            ['Mid Duty', '14:00', '23:00'],
            ['Night Duty', '22:00', '07:00'],
        ] as [$name, $start, $end]) {
            Shift::query()->firstOrCreate(['name' => $name], ['start_time' => $start, 'end_time' => $end, 'grace_minutes' => 10, 'is_default' => false]);
        }
    }
}
