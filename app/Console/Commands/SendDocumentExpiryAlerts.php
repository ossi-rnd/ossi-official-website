<?php

namespace App\Console\Commands;

use App\Actions\Documents\SendDocumentExpiryAlerts as SendAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled daily in routes/console.php. Safe to run by hand: each alert is
 * claimed in sent_alerts and never sent twice.
 */
final class SendDocumentExpiryAlerts extends Command
{
    protected $signature = 'app:send-document-alerts';

    protected $description = 'Warn Admin about licenses and documents due for renewal';

    public function handle(SendAlerts $sendAlerts): int
    {
        $sent = $sendAlerts->handle(CarbonImmutable::today());

        $this->info("Sent {$sent} document ".str('alert')->plural($sent).'.');

        return self::SUCCESS;
    }
}
