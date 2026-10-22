<?php

namespace App\Console\Commands;

use App\Actions\People\SendBirthdayReminders as SendReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled each morning in routes/console.php. Safe to run by hand: each birthday
 * is announced once a year.
 */
final class SendBirthdayReminders extends Command
{
    protected $signature = 'app:send-birthday-reminders';

    protected $description = 'Tell HR and Operations whose birthday it is today';

    public function handle(SendReminders $sendReminders): int
    {
        $sent = $sendReminders->handle(CarbonImmutable::today());

        $this->info("Announced {$sent} ".str('birthday')->plural($sent).'.');

        return self::SUCCESS;
    }
}
