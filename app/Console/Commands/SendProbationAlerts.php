<?php

namespace App\Console\Commands;

use App\Actions\People\SendProbationAlerts as SendAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled daily in routes/console.php. Safe to run by hand: an alert that
 * was already sent is never sent again.
 */
final class SendProbationAlerts extends Command
{
    protected $signature = 'app:send-probation-alerts';

    protected $description = 'Notify HR about probation periods that end soon or today';

    public function handle(SendAlerts $sendAlerts): int
    {
        $sent = $sendAlerts->handle(CarbonImmutable::today());

        $this->info("Sent {$sent} probation ".str('alert')->plural($sent).'.');

        return self::SUCCESS;
    }
}
