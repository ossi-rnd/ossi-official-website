<?php

namespace App\Console\Commands;

use App\Actions\Patrols\CloseForgottenAways as CloseAways;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled every 15 minutes in routes/console.php. Safe to run by hand:
 * only absences still open an hour after their duty's end are closed.
 */
final class CloseForgottenAways extends Command
{
    protected $signature = 'app:close-forgotten-aways';

    protected $description = 'Close time away that nobody ended (no "Back", no clock-out) at the end of the duty';

    public function handle(CloseAways $close): int
    {
        $closed = $close->handle(CarbonImmutable::now());

        $this->info("Closed {$closed} forgotten ".str('absence')->plural($closed).'.');

        return self::SUCCESS;
    }
}
