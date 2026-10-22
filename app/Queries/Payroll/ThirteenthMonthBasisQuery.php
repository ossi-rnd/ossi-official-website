<?php

namespace App\Queries\Payroll;

use App\Enums\FinalPayStatus;
use App\Enums\ThirteenthMonthStatus;
use App\Models\FinalPay;
use App\Models\PayslipRecord;
use App\Models\ThirteenthMonthPayout;
use Carbon\CarbonImmutable;

/**
 * Each employee's 13th month (PD 851) for a year, from generated payslips:
 *
 *   earned = (basic pay − late deductions) ÷ 12, over the year's payslips up to `coversUntil`
 *   amount = earned − what approved runs of the same year already paid
 *
 * Basic pay only: overtime, holiday premium, night differential and
 * adjustments are not part of it (Revised Guidelines on PD 851). Everyone with
 * a payslip in the year counts, including those who have since left.
 */
final class ThirteenthMonthBasisQuery
{
    /**
     * @return array<int, array{days_worked: int, basic_pay: float, late_deductions: float, earned: float, earlier_paid: float, amount: float, cutoffs: list<array{payroll_run_id: int, period: string, basic_pay: float, late_deduction: float}>}> keyed by user_id
     */
    public function handle(int $year, CarbonImmutable $coversUntil, ?int $exceptRunId = null): array
    {
        $rows = [];

        PayslipRecord::query()
            ->with('payrollRun')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslip_records.payroll_run_id')
            ->whereDate('payroll_runs.end_date', '>=', "{$year}-01-01")
            ->whereDate('payroll_runs.end_date', '<=', min($coversUntil->toDateString(), "{$year}-12-31"))
            ->orderBy('payroll_runs.start_date')
            ->select('payslip_records.*')
            ->orderBy('payslip_records.id')
            ->lazy(500)
            ->each(function (PayslipRecord $record) use (&$rows) {
                $row = $rows[$record->user_id] ?? ['days_worked' => 0, 'basic_pay' => 0.0, 'late_deductions' => 0.0, 'earned' => 0.0, 'earlier_paid' => 0.0, 'amount' => 0.0, 'cutoffs' => []];
                $row['days_worked'] += $record->days_worked;
                $row['basic_pay'] += (float) $record->basic_pay;
                $row['late_deductions'] += (float) $record->late_deduction;
                $row['cutoffs'][] = ['payroll_run_id' => $record->payroll_run_id, 'period' => $record->payrollRun->periodLabel(), 'basic_pay' => (float) $record->basic_pay, 'late_deduction' => (float) $record->late_deduction];
                $rows[$record->user_id] = $row;
            });

        $earlier = ThirteenthMonthPayout::query()
            ->whereHas('run', fn ($run) => $run->where('year', $year)->whereIn('status', [ThirteenthMonthStatus::Approved, ThirteenthMonthStatus::Paid])->when($exceptRunId, fn ($q) => $q->whereKeyNot($exceptRunId)))
            ->selectRaw('user_id, SUM(amount) as paid')
            ->groupBy('user_id')
            ->pluck('paid', 'user_id');

        // Also what a released final pay already paid (someone who resigned): December's run must not pay it again.
        FinalPay::query()->where('status', FinalPayStatus::Released)->where('thirteenth_year', $year)
            ->selectRaw('user_id, SUM(thirteenth_amount) as paid')->groupBy('user_id')->pluck('paid', 'user_id')
            ->each(function (mixed $paid, int $userId) use (&$earlier) {
                $earlier[$userId] = (float) ($earlier[$userId] ?? 0) + (float) $paid;
            });

        foreach ($rows as $userId => $row) {
            $row['basic_pay'] = round($row['basic_pay'], 2);
            $row['late_deductions'] = round($row['late_deductions'], 2);
            $row['earned'] = round(max(0, $row['basic_pay'] - $row['late_deductions']) / 12, 2);
            $row['earlier_paid'] = round((float) ($earlier[$userId] ?? 0), 2);
            $row['amount'] = round(max(0, $row['earned'] - $row['earlier_paid']), 2);
            $rows[$userId] = $row;
        }

        return $rows;
    }
}
