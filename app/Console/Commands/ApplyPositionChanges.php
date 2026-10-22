<?php

namespace App\Console\Commands;

use App\Actions\People\ApplyPositionChanges as Apply;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled daily in routes/console.php, just after midnight. Safe to run by
 * hand: each change is applied once.
 */
final class ApplyPositionChanges extends Command
{
    protected $signature = 'app:apply-position-changes';

    protected $description = 'Put promotions and changes of position on the employee record on their day';

    public function handle(Apply $apply): int
    {
        $count = $apply->handle(CarbonImmutable::today());

        $this->info("Applied {$count} ".str('change')->plural($count).' of position.');

        return self::SUCCESS;
    }
}
