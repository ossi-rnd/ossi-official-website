<?php

namespace App\Jobs;

use App\Actions\Payroll\GenerateRunPayslips;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\UpdateNotice;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Generate all" for one payroll run, in the background, in batches of 100. After each batch the
 * run's progress is saved (the page polls it for its bar). One per run at a time (unique by run).
 * Interrupted or failed, clicking "Generate all" again carries on: anyone with a payslip is skipped.
 * Whoever started it gets a bell when it's done, or if it fails.
 */
final class GenerateRunPayslipsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One try: a half-done run is resumed by starting it again, not retried blindly. */
    public int $tries = 1;

    /** Generous: thousands of payslips. */
    public int $timeout = 3600;

    public function __construct(public readonly int $runId, public readonly int $actorId) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(GenerateRunPayslips $generate): void
    {
        $run = PayrollRun::query()->findOrFail($this->runId);
        $actor = User::query()->findOrFail($this->actorId);

        $run->forceFill(['generation_status' => 'running'])->save();

        $generated = $generate->handle($run, $actor, $skippedNoRate, function (int $processed, int $generatedSoFar, int $noRateSoFar) use ($run) {
            $run->forceFill([
                'generation_done' => $processed,
                'generation_generated' => $generatedSoFar,
                'generation_skipped_no_rate' => $noRateSoFar,
                // The estimate can be low if more became due meanwhile: never show more than 100%.
                'generation_total' => max($run->generation_total, $processed),
            ])->save();
        });

        $run->forceFill([
            'generation_status' => 'done',
            'generation_done' => max($run->generation_done, $run->generation_total),
            'generation_generated' => $generated,
            'generation_skipped_no_rate' => $skippedNoRate,
            'generation_finished_at' => now(),
        ])->save();

        $actor->notify(new UpdateNotice(
            "Payslips generated: {$run->periodLabel()}",
            "{$generated} generated.".($skippedNoRate > 0 ? " {$skippedNoRate} skipped: no daily rate (set it on Daily Rates, then Generate all again)." : ''),
            route('payroll', ['run' => $run->id]), 'banknotes'));
    }

    public function failed(?Throwable $exception): void
    {
        $run = PayrollRun::query()->find($this->runId);
        $run?->forceFill(['generation_status' => 'failed', 'generation_finished_at' => now()])->save();

        User::query()->find($this->actorId)?->notify(new UpdateNotice(
            'Payslip generation stopped'.($run ? ": {$run->periodLabel()}" : ''),
            'It stopped partway. The payslips already made are kept; click Generate all again to carry on.',
            route('payroll', $run ? ['run' => $run->id] : []), 'banknotes'));
    }
}
