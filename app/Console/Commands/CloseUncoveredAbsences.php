<?php

namespace App\Console\Commands;

use App\Actions\Relievers\CloseUncoveredAbsences as CloseUncovered;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled daily at 00:15 in routes/console.php. Safe to run by hand: only
 * absences still open after their day has passed become "Not covered".
 */
final class CloseUncoveredAbsences extends Command
{
    protected $signature = 'app:close-uncovered-absences';

    protected $description = 'Mark absences whose day passed with no reliever as "Not covered"';

    public function handle(CloseUncovered $close): int
    {
        $closed = $close->handle(CarbonImmutable::today());

        $this->info("Marked {$closed} ".str('absence')->plural($closed).' as not covered.');

        return self::SUCCESS;
    }
}
