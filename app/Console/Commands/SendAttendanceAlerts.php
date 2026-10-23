<?php

namespace App\Console\Commands;

use App\Actions\Attendance\SendAttendanceAlerts as SendAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled every 5 minutes in routes/console.php. Safe to run by hand: each alert is
 * claimed in sent_alerts and never sent twice.
 */
final class SendAttendanceAlerts extends Command
{
    protected $signature = 'app:send-attendance-alerts';

    protected $description = 'Remind missed clock-outs (then tell the head) and office staff who haven\'t clocked in';

    public function handle(SendAlerts $sendAlerts): int
    {
        $sent = $sendAlerts->handle(CarbonImmutable::now());

        $this->info("Clock-out reminders: {$sent['reminded']}, to heads: {$sent['escalated']}; office staff not clocked in: {$sent['officeMissing']}.");

        return self::SUCCESS;
    }
}
