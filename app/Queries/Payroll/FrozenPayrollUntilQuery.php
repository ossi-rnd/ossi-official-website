<?php

namespace App\Queries\Payroll;

use App\Models\PayslipRecord;
use Carbon\CarbonImmutable;

/**
 * The last day covered by any generated payslip. Rules (tax, contributions,
 * minimum wage) may only change from the day after: a frozen payslip and its
 * "how this was computed" must keep telling the truth.
 */
final class FrozenPayrollUntilQuery
{
    public function handle(): ?CarbonImmutable
    {
        $last = PayslipRecord::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslip_records.payroll_run_id')
            ->max('payroll_runs.end_date');

        return $last !== null ? CarbonImmutable::parse((string) $last)->startOfDay() : null;
    }

    /**
     * A ValidationException-ready message when $from is too early, else null.
     */
    public function tooEarly(CarbonImmutable $from): ?string
    {
        $until = $this->handle();

        return $until !== null && $from->lessThanOrEqualTo($until)
            ? "Payslips up to {$until->format('M j, Y')} are already generated with the current rules. Choose {$until->addDay()->format('M j, Y')} or later."
            : null;
    }
}
