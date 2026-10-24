<?php

namespace App\Console\Commands;

use App\Actions\People\ApplyProbationEnds;
use App\Actions\People\SendProbationAlerts as SendAlerts;
use App\Actions\People\SendTrainingAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled daily in routes/console.php. First closes probations that ended (Regular, or
 * employment ended after an approved fail), then alerts about probations and training ending.
 * Safe to run by hand: each step happens once.
 */
final class SendProbationAlerts extends Command
{
    protected $signature = 'app:send-probation-alerts';

    protected $description = 'Close ended probations, and notify HR and heads about probation and training ending';

    public function handle(ApplyProbationEnds $applyEnds, SendAlerts $sendAlerts, SendTrainingAlerts $sendTraining): int
    {
        $today = CarbonImmutable::today();

        $closed = $applyEnds->handle();
        $sent = $sendAlerts->handle($today);
        $training = $sendTraining->handle($today);

        if ($closed > 0) {
            $this->info("Closed {$closed} ".str('probation')->plural($closed).'.');
        }
        $this->info("Sent {$sent} probation ".str('alert')->plural($sent).'.');
        if ($training > 0) {
            $this->info("Sent {$training} training ".str('alert')->plural($training).'.');
        }

        return self::SUCCESS;
    }
}
