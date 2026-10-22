<?php

namespace App\Console\Commands;

use App\Actions\Operations\SendRadioPermitAlerts as SendAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled daily in routes/console.php. Safe to run by hand: each alert is
 * claimed in sent_alerts and never sent twice.
 */
final class SendRadioPermitAlerts extends Command
{
    protected $signature = 'app:send-radio-permit-alerts';

    protected $description = 'Warn Admin and Operation HQ about radio NTC permits due for renewal';

    public function handle(SendAlerts $sendAlerts): int
    {
        $sent = $sendAlerts->handle(CarbonImmutable::today());

        $this->info("Sent {$sent} radio permit ".str('alert')->plural($sent).'.');

        return self::SUCCESS;
    }
}
