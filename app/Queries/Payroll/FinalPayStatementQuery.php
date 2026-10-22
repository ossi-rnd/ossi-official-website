<?php

namespace App\Queries\Payroll;

use App\Enums\DeductionType;
use App\Models\EmployeeDeduction;
use App\Models\PayslipRecord;
use App\Models\Resignation;
use App\Models\TimeLog;
use Carbon\CarbonImmutable;

/**
 * The final-pay statement of a resignation, computed from what the system
 * already knows, one line per item with where it came from:
 *
 *   + 13th month, pro-rated to the last day (ThirteenthMonthBasisQuery: earned − already paid)
 *   + cash bond refund (CashBondBalanceQuery)
 *   ± Accounting's adjustments (each with a reason)
 *   − what's still owed on loans, cash advances and load, only with the employee's
 *     written consent (Labor Code Art. 113); without it, shown but not deducted
 *   = net, never below zero; anything left owed is a receivable, collected separately
 *
 * The last salary is NOT added: the person is on payroll until their last day, so
 * the regular run pays it. It's shown as an "info" line, with a warning when days
 * worked have no payslip yet, so nothing is paid twice or forgotten.
 *
 * @phpstan-type Line array{kind: string, label: string, amount: float, note: string|null, deduction_id?: int}
 * @phpstan-type Statement array{
 *     lines: list<Line>, total_pay: float, total_deducted: float, net: float, receivable: float,
 *     thirteenth_year: int, thirteenth_amount: float, cash_bond_refund: float,
 *     owed: list<array{deduction_id: int, amount: float}>, warnings: list<string>
 * }
 */
final class FinalPayStatementQuery
{
    public function __construct(
        private readonly ThirteenthMonthBasisQuery $thirteenth,
        private readonly CashBondBalanceQuery $cashBond,
    ) {}

    /**
     * @param  list<array{label: string, amount: float, reason: string, by: string}>  $adjustments
     * @return Statement
     */
    public function handle(Resignation $resignation, array $adjustments = []): array
    {
        $userId = $resignation->user_id;
        $lastDay = $resignation->effectiveLastDay();
        $lines = [];
        $warnings = [];

        // The last salary: paid in the regular payroll. Shown, not counted.
        $lastPayslip = PayslipRecord::query()->with('payrollRun')->where('user_id', $userId)
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslip_records.payroll_run_id')
            ->orderByDesc('payroll_runs.end_date')->select('payslip_records.*')->first();
        $coveredUntil = $lastPayslip?->payrollRun->end_date;
        $unpaidDays = TimeLog::query()->where('user_id', $userId)
            ->whereDate('log_date', '<=', $lastDay->toDateString())
            ->when($coveredUntil, fn ($query, CarbonImmutable $until) => $query->whereDate('log_date', '>', $until->toDateString()))
            ->count();

        if ($lastPayslip !== null) {
            $run = $lastPayslip->payrollRun;
            $lines[] = self::line('info', 'Salary up to the last day', (float) $lastPayslip->net_pay,
                "Paid in the regular payroll: {$run->periodLabel()} ({$run->status->label()}), pay day {$run->pay_date->format('M j, Y')}. Not added here, so it isn't paid twice.");
        }

        if ($unpaidDays > 0) {
            $warnings[] = "{$unpaidDays} ".str('day')->plural($unpaidDays).' worked up to the last day '.($unpaidDays === 1 ? 'has' : 'have').' no payslip yet. Generate the last cut-off in Payroll so the salary is paid there.';
        }

        // 13th month, pro-rated to the last day.
        $year = (int) $lastDay->format('Y');
        $thirteenth = $this->thirteenth->handle($year, $lastDay)[$userId] ?? null;
        $thirteenthAmount = round((float) ($thirteenth['amount'] ?? 0), 2);
        $lines[] = self::line('pay', "13th month, {$year} (pro-rated)", $thirteenthAmount, $thirteenth === null
            ? 'No generated payslips this year.'
            : '₱'.number_format((float) $thirteenth['earned'], 2).' earned to '.$lastDay->format('M j').($thirteenth['earlier_paid'] > 0 ? ' − ₱'.number_format((float) $thirteenth['earlier_paid'], 2).' already paid' : '').'.');

        // Cash bond, refunded.
        $bond = $this->cashBond->forUser($userId);
        $refund = max(0.0, $bond['balance']);
        if ($refund > 0 || $bond['opening'] > 0 || $bond['collected'] > 0) {
            $lines[] = self::line('pay', 'Cash bond refund', $refund,
                '₱'.number_format($bond['opening'], 2).' paid in before the system + ₱'.number_format($bond['collected'], 2).' from payslips'.($bond['adjustments'] !== 0.0 ? ' ± ₱'.number_format($bond['adjustments'], 2).' corrections' : '').'.');
        }

        // Accounting's adjustments.
        foreach ($adjustments as $adjustment) {
            $amount = round((float) $adjustment['amount'], 2);
            $lines[] = self::line($amount >= 0 ? 'pay' : 'deduct', $adjustment['label'], abs($amount), "{$adjustment['reason']} ({$adjustment['by']})");
        }

        // What's still owed: deducted only with written consent.
        $owed = [];
        $notDeducted = 0;
        $consent = $resignation->deduction_consent_at !== null;
        $balanceTypes = array_map(fn (DeductionType $type) => $type->value, array_values(array_filter(DeductionType::cases(), fn (DeductionType $type) => $type->tracksBalance())));

        $debts = EmployeeDeduction::query()->active()->where('user_id', $userId)->whereIn('type', $balanceTypes)
            ->where('remaining_balance', '>', 0)->orderBy('id')->get();

        foreach ($debts as $deduction) {
            $amount = round((float) $deduction->remaining_balance, 2);

            if ($consent) {
                // The loan's id rides along, so releasing settles exactly this loan.
                $lines[] = [...self::line('deduct', "{$deduction->type->label()}: {$deduction->label}", $amount, 'Still owed.'), 'deduction_id' => $deduction->id];
                $owed[] = ['deduction_id' => $deduction->id, 'amount' => $amount];
            } else {
                $lines[] = self::line('info', "{$deduction->type->label()}: {$deduction->label}", $amount, 'Still owed, not deducted: no written consent (Labor Code Art. 113). Collect it separately.');
                $notDeducted++;
            }
        }

        if ($notDeducted > 0) {
            $warnings[] = 'The employee hasn\'t consented to deductions, so what they owe isn\'t taken from this final pay.';
        }

        $pay = round(array_sum(array_map(fn (array $line) => $line['kind'] === 'pay' ? $line['amount'] : 0.0, $lines)), 2);
        $deducted = round(array_sum(array_map(fn (array $line) => $line['kind'] === 'deduct' ? $line['amount'] : 0.0, $lines)), 2);

        return [
            'lines' => $lines,
            'total_pay' => $pay,
            'total_deducted' => $deducted,
            'net' => round(max(0.0, $pay - $deducted), 2),
            'receivable' => round(max(0.0, $deducted - $pay), 2),
            'thirteenth_year' => $year,
            'thirteenth_amount' => $thirteenthAmount,
            'cash_bond_refund' => round($refund, 2),
            'owed' => $owed,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return Line
     */
    private static function line(string $kind, string $label, float $amount, ?string $note): array
    {
        return ['kind' => $kind, 'label' => $label, 'amount' => round($amount, 2), 'note' => $note];
    }
}
