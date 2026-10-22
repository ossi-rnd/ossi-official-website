<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The scheduler. Laravel Cloud runs it for you (enable "Scheduler" on the
| environment); locally run `php artisan schedule:work`. Every command here
| is idempotent, so a missed or doubled run never sends anything twice.
| Times are APP_TIMEZONE (Asia/Manila).
*/

// The day after an accepted resignation's last day: status Resigned, account closed.
Schedule::command('app:apply-separations')->dailyAt('00:10')->withoutOverlapping()->onOneServer();
// Absences whose day passed with no reliever: "Not covered" (the post went without a guard).
Schedule::command('app:close-uncovered-absences')->dailyAt('00:15')->withoutOverlapping()->onOneServer();
Schedule::command('app:apply-position-changes')->dailyAt('00:05')->withoutOverlapping()->onOneServer();

Schedule::command('app:send-probation-alerts')->dailyAt('07:00')->withoutOverlapping()->onOneServer();

Schedule::command('app:send-requirement-alerts')->dailyAt('07:05')->withoutOverlapping()->onOneServer();

Schedule::command('app:send-document-alerts')->dailyAt('07:10')->withoutOverlapping()->onOneServer();

// Whose birthday it is today, to HR and (for guards) Operations.
Schedule::command('app:send-birthday-reminders')->dailyAt('07:15')->withoutOverlapping()->onOneServer();

// NTC permits of radios nearing expiry, to Admin and Operation HQ.
Schedule::command('app:send-radio-permit-alerts')->dailyAt('07:20')->withoutOverlapping()->onOneServer();

Schedule::command('app:send-patrol-alerts')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('app:detect-no-shows')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('app:close-forgotten-aways')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('app:prune-away-locations')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

Schedule::command('app:publish-announcements')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
