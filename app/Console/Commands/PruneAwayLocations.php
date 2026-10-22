<?php

namespace App\Console\Commands;

use App\Actions\Patrols\PruneAwayLocations as Prune;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled nightly in routes/console.php. Safe to run by hand: a trail
 * already deleted is skipped.
 */
final class PruneAwayLocations extends Command
{
    protected $signature = 'app:prune-away-locations';

    protected $description = 'Delete the GPS trail of time away older than the retention period (the summary is kept)';

    public function handle(Prune $prune): int
    {
        $pruned = $prune->handle(CarbonImmutable::now());

        $this->info("Deleted the location trail of {$pruned} ".str('absence')->plural($pruned).'.');

        return self::SUCCESS;
    }
}
