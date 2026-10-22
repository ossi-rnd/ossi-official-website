<?php

namespace App\Queries\Payroll;

use App\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Models\PayslipRecord;
use Illuminate\Database\Eloquent\Collection;

/**
 * The payroll at a glance, from frozen payslip records only: the latest run's
 * net and deductions mix, the cost trend over the last runs, and what the
 * next pay day is. One source for the Payroll page and the dashboard, so the
 * two never show different totals.
 *
 * @phpstan-type PayrollOverview array{
 *     latest_net: float, latest_label: string|null, previous_net: float|null, change_pct: int|null,
 *     processing_net: float, latest_deductions: float, paid_count: int,
 *     breakdown: array<string, float>, trend: list<array{label: string, gross: float, net: float}>,
 *     next_pay_day: string|null
 * }
 */
final class PayrollOverviewQuery
{
    /**
     * The last runs with their payslip count and totals (the Payroll page's list too).
     *
     * @return Collection<int, PayrollRun>
     */
    public static function recentRuns(): Collection
    {
        return PayrollRun::query()
            ->withCount('payslipRecords')
            ->withSum('payslipRecords as total_gross', 'gross_pay')
            ->withSum('payslipRecords as total_net', 'net_pay')
            ->orderByDesc('start_date')
            ->limit(24)
            ->get();
    }

    /**
     * @param  Collection<int, PayrollRun>|null  $runs  recentRuns(), when the caller has it already
     * @return PayrollOverview
     */
    public function handle(?Collection $runs = null): array
    {
        $runs ??= self::recentRuns();
        $withPayslips = $runs->filter(fn (PayrollRun $run) => $run->payslip_records_count > 0)->values();
        $latest = $withPayslips->first();
        $previous = $withPayslips->get(1);
        $processing = $runs->firstWhere('status', PayrollRunStatus::Processing);
        $paid = $runs->firstWhere('status', PayrollRunStatus::Paid);
        // The soonest pay day still ahead: the earliest unpaid run's.
        $next = $runs->filter(fn (PayrollRun $run) => $run->status !== PayrollRunStatus::Paid)->sortBy('pay_date')->first();

        $breakdown = $latest !== null
            ? PayslipRecord::query()->where('payroll_run_id', $latest->id)
                ->selectRaw('SUM(sss) as sss, SUM(philhealth) as philhealth, SUM(pagibig) as pagibig, SUM(withholding_tax) as tax, SUM(late_deduction) as late, SUM(loan_deductions) as loans, SUM(carryover_deductions) as carried, SUM(total_deductions) as total')
                ->first()?->toArray() ?? []
            : [];

        $latestNet = (float) ($latest->total_net ?? 0);
        $previousNet = $previous !== null ? (float) $previous->total_net : null;

        return [
            'latest_net' => $latestNet,
            'latest_label' => $latest?->periodLabel(),
            'previous_net' => $previousNet,
            'change_pct' => $previousNet !== null && $previousNet > 0 ? (int) round(($latestNet - $previousNet) / $previousNet * 100) : null,
            'processing_net' => (float) ($processing->total_net ?? 0),
            'latest_deductions' => (float) ($breakdown['total'] ?? 0),
            'paid_count' => (int) ($paid->payslip_records_count ?? 0),
            'breakdown' => array_map('floatval', array_intersect_key($breakdown, array_flip(['sss', 'philhealth', 'pagibig', 'tax', 'carried', 'late', 'loans']))),
            'trend' => array_values($withPayslips->take(6)->reverse()
                ->map(fn (PayrollRun $run) => ['label' => $run->start_date->format('M j'), 'gross' => (float) $run->total_gross, 'net' => (float) $run->total_net])->all()),
            'next_pay_day' => $next?->pay_date->toDateString(),
        ];
    }
}
