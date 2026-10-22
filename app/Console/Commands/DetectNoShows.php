<?php

namespace App\Console\Commands;

use App\Actions\Relievers\DetectNoShows as Detect;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled every 5 minutes in routes/console.php. Safe to run by hand:
 * each no-show is raised once and each escalation is sent once.
 */
final class DetectNoShows extends Command
{
    protected $signature = 'app:detect-no-shows';

    protected $description = 'Alert Operations when a guard on the roster neither clocks in nor reports, and when an absence stays uncovered';

    public function handle(Detect $detect): int
    {
        ['noShows' => $noShows, 'escalated' => $escalated] = $detect->handle(CarbonImmutable::now());

        $this->info("{$noShows} ".str('no-show')->plural($noShows).", {$escalated} ".str('escalation')->plural($escalated).'.');

        return self::SUCCESS;
    }
}
