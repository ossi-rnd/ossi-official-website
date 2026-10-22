<?php

namespace App\Actions\Payroll;

use App\Models\PayrollRun;
use App\Models\User;
use App\Queries\People\EffectiveRatesQuery;
use Illuminate\Support\Collection;

/**
 * "Generate all": one transaction PER EMPLOYEE (inside GeneratePayslip), so
 * one bad payslip doesn't undo the other forty-nine.
 *
 * Runs in the request. Fine for a few hundred employees; past that, move this
 * loop into a queued job and show progress (see docs/features/08-payroll.md).
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
     * @param-out int $skippedNoRate how many who worked were skipped for having no daily rate
     *
     * @return int how many payslips were generated
     */
    public function handle(PayrollRun $run, User $actor, ?int &$skippedNoRate = null): int
    {
        $generated = 0;
        $skippedNoRate = 0;

        // Once for everyone, before anything is frozen: pay that changed on earlier cut-offs.
        $this->corrections->handle($run);
        $this->paidLeave->handle($run);

        User::query()
            ->onPayroll()
            // Worked in the period, or has accepted paid leave on it.
            ->dueAPayslip($run)
            ->whereDoesntHave('payslipRecords', fn ($query) => $query->where('payroll_run_id', $run->id))
            // Skipped, not failed: their payslip waits until the second person decides.
            ->whereDoesntHave('payrollAdjustments', fn ($query) => $query->where('payroll_run_id', $run->id)->pending())
            // Skipped, not failed: a correction from an earlier cut-off for Accounting to accept or dismiss.
            ->whereDoesntHave('priorPeriodCorrections', fn ($query) => $query->where('payroll_run_id', $run->id)->suggested())
            // Skipped, not failed: paid leave for Accounting to accept or dismiss.
            ->whereDoesntHave('paidLeavePayments', fn ($query) => $query->where('payroll_run_id', $run->id)->suggested())
            ->with('employeeProfile')
            ->orderBy('id')
            ->chunkById(100, function (Collection $employees) use ($run, $actor, &$generated, &$skippedNoRate) {
                $rates = $this->rates->handle($employees->map(fn (User $user) => $user->employeeProfile)->filter()->values()->all(), $run->start_date);

                foreach ($employees as $employee) {
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
            });

        return $generated;
    }
}
