<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Jobs\GenerateRunPayslipsJob;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Generate all", started from the run page: the payslips are built in the background
 * (GenerateRunPayslipsJob), so a run of thousands doesn't hang one request or time out. The run
 * keeps the progress for the page's bar; one generation per run at a time. Whoever started it is
 * told when it's done.
 */
final class StartPayslipGeneration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GenerateRunPayslips $generate,
    ) {}

    public function handle(PayrollRun $run, User $actor): PayrollRun
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        $run = DB::transaction(function () use ($run, $actor) {
            $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->isGenerating()) {
                throw ValidationException::withMessages(['generate' => 'Payslips are already being generated for this run.']);
            }

            $locked->forceFill([
                'generation_status' => 'queued',
                'generation_total' => $this->generate->countDue($locked),
                'generation_done' => 0,
                'generation_generated' => 0,
                'generation_skipped_no_rate' => 0,
                'generation_by' => $actor->id,
                'generation_started_at' => now(),
                'generation_finished_at' => null,
            ])->save();

            $this->audit->record(AuditArea::Payroll, 'payroll_run.generation_started', "Started generating payslips for {$locked->periodLabel()} ({$locked->generation_total} due)", $locked, causer: $actor);

            return $locked;
        });

        // After the commit, so the worker sees the run as queued.
        GenerateRunPayslipsJob::dispatch($run->id, $actor->id);

        return $run->refresh();
    }
}
