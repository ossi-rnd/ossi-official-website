<?php

namespace App\Console\Commands;

use App\Actions\Resignations\ApplySeparations as Apply;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled daily in routes/console.php, just after midnight. Safe to run by
 * hand: each person is separated once.
 */
final class ApplySeparations extends Command
{
    protected $signature = 'app:apply-separations';

    protected $description = 'Mark people Resigned the day after their accepted last day';

    public function handle(Apply $apply): int
    {
        $count = $apply->handle(CarbonImmutable::today());

        $this->info("Separated {$count} ".str('employee')->plural($count).'.');

        return self::SUCCESS;
    }
}
