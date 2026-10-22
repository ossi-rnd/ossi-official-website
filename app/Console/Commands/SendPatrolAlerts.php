<?php

namespace App\Console\Commands;

use App\Actions\Patrols\SendPatrolAlerts as SendAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled every 15 minutes in routes/console.php. Safe to run by hand:
 * each alert is claimed in sent_alerts and never sent twice.
 */
final class SendPatrolAlerts extends Command
{
    protected $signature = 'app:send-patrol-alerts';

    protected $description = 'Warn Operations about missed patrol rounds and guards away from the post too long';

    public function handle(SendAlerts $sendAlerts): int
    {
        $sent = $sendAlerts->handle(CarbonImmutable::now());

        $this->info("Sent {$sent} patrol ".str('alert')->plural($sent).'.');

        return self::SUCCESS;
    }
}
