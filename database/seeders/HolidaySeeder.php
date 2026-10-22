<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * Fixed-date Philippine holidays for this year. Movable ones (Holy Week, Eid,
 * National Heroes Day) change by proclamation every year: add them on the
 * Holidays page.
 */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $year = now()->year;

        $holidays = [
            ['01-01', "New Year's Day", 'regular'],
            ['04-09', 'Araw ng Kagitingan', 'regular'],
            ['05-01', 'Labor Day', 'regular'],
            ['06-12', 'Independence Day', 'regular'],
            ['08-21', 'Ninoy Aquino Day', 'special_non_working'],
            ['11-01', "All Saints' Day", 'special_non_working'],
            ['11-30', 'Bonifacio Day', 'regular'],
            ['12-08', 'Feast of the Immaculate Conception', 'special_non_working'],
            ['12-25', 'Christmas Day', 'regular'],
            ['12-30', 'Rizal Day', 'regular'],
            ['12-31', 'Last Day of the Year', 'special_non_working'],
        ];

        foreach ($holidays as [$day, $name, $type]) {
            // whereDate: SQLite stores the date cast as "Y-m-d 00:00:00", so a plain match would duplicate.
            $holiday = Holiday::query()->whereDate('date', "{$year}-{$day}")->first() ?? new Holiday;
            $holiday->fill(['date' => "{$year}-{$day}", 'name' => $name, 'type' => $type])->save();
        }
    }
}
