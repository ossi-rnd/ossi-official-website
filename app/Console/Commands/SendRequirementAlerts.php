<?php

namespace App\Console\Commands;

use App\Actions\People\SendRequirementAlerts as SendAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled daily in routes/console.php. Safe to run by hand: each alert is
 * claimed in sent_alerts and never sent twice.
 */
final class SendRequirementAlerts extends Command
{
    protected $signature = 'app:send-requirement-alerts';

    protected $description = 'Warn about 201 documents that expire soon, have expired, or are past their promised date';

    public function handle(SendAlerts $sendAlerts): int
    {
        $sent = $sendAlerts->handle(CarbonImmutable::today());

        $this->info("Sent {$sent} 201 document ".str('alert')->plural($sent).'.');

        return self::SUCCESS;
    }
}
