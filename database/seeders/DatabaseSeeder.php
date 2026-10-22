<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data always; demo people and history only outside production.
     * In production, create the first account with `php artisan app:create-super-admin`.
     */
    public function run(): void
    {
        $this->call([ShiftSeeder::class, HolidaySeeder::class]);

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
