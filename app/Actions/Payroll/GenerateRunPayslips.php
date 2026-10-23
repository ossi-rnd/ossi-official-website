<?php

namespace App\Actions\Payroll;

use App\Models\PayrollRun;
use App\Models\User;
use App\Queries\People\EffectiveRatesQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Generate all": one transaction PER EMPLOYEE (inside GeneratePayslip), so
 * one bad payslip doesn't undo the other forty-nine.
 *
 * From the run page it runs in the background (StartPayslipGeneration →
 * GenerateRunPayslipsJob), in batches of 100, reporting after each batch ($afterBatch)
 * so the page can show a progress bar. Safe to run again: anyone who already has a
 * payslip is skipped, so an interrupted run carries on where it stopped.
 */
final class GenerateRunPayslips
{
    public function __construct(
        private readonly GeneratePayslip $generatePayslip,
        private readonly EffectiveRatesQuery $rates,
        private readonly DetectPriorPeriodCorrections $corrections,
        private readonly DetectPaidLeave $paidLeave,
    ) {}

    /**
     * How many are due a payslip and don't have one yet (the progress bar's total). An estimate:
     * a few may still be skipped (no rate, an earlier cutoff first).
     */
    public function countDue(PayrollRun $run): int
    {
        return $this->dueQuery($run)->count();
    }

    /**
     * @param  (callable(int $processed, int $generated, int $skippedNoRate): void)|null  $afterBatch  called after every batch of 100, with running totals
     *
     * @param-out int $skippedNoRate how many who worked were skipped for having no daily rate
     *
     * @return int how many payslips were generated
     */
    public function handle(PayrollRun $run, User $actor, ?int &$skippedNoRate = null, ?callable $afterBatch = null): int
    {
        $generated = 0;
        $skippedNoRate = 0;
        $processed = 0;

        // Once for everyone, before anything is frozen: pay that changed on earlier cut-offs.
        $this->corrections->handle($run);
        $this->paidLeave->handle($run);

        $this->dueQuery($run)
            ->with('employeeProfile')
            ->orderBy('id')
            ->chunkById(100, function (Collection $employees) use ($run, $actor, &$generated, &$skippedNoRate, &$processed, $afterBatch) {
                $rates = $this->rates->handle($employees->map(fn (User $user) => $user->employeeProfile)->filter()->values()->all(), $run->start_date);

                foreach ($employees as $employee) {
                    $processed++;

                    // Skipped, not failed: no daily rate yet (the run page lists them for Accounting).
                    if (($rates[$employee->employeeProfile->id ?? 0]->rate ?? 0.0) <= 0) {
                        $skippedNoRate++;

                        continue;
                    }

                    // Skipped, not failed: the earlier cutoff of the month comes first.
                    if ($this->generatePayslip->blockingEarlierRun($run, $employee) !== null) {
                        continue;
                    }

                    $this->generatePayslip->handle($run, $employee, $actor, correctionsChecked: true);
                    $generated++;
                }

                if ($afterBatch !== null) {
                    $afterBatch($processed, $generated, $skippedNoRate);
                }
            });

        return $generated;
    }

    /**
     * Everyone due a payslip on this run who doesn't have one yet, and isn't waiting on a decision.
     *
     * @return Builder<User>
     */
    private function dueQuery(PayrollRun $run): Builder
    {
        return User::query()
            ->onPayroll()
            // Worked in the period, or has accepted paid leave on it.
            ->dueAPayslip($run)
            ->whereDoesntHave('payslipRecords', fn ($query) => $query->where('payroll_run_id', $run->id))
            // Skipped, not failed: their payslip waits until the second person decides.
            ->whereDoesntHave('payrollAdjustments', fn ($query) => $query->where('payroll_run_id', $run->id)->pending())
            // Skipped, not failed: a correction from an earlier cut-off for Accounting to accept or dismiss.
            ->whereDoesntHave('priorPeriodCorrections', fn ($query) => $query->where('payroll_run_id', $run->id)->suggested())
            // Skipped, not failed: paid leave for Accounting to accept or dismiss.
            ->whereDoesntHave('paidLeavePayments', fn ($query) => $query->where('payroll_run_id', $run->id)->suggested());
    }
}
