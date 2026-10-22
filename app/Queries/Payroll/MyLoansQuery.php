<?php

namespace App\Queries\Payroll;

use App\Enums\DeductionType;
use App\Models\EmployeeDeduction;
use App\Models\PayslipRecord;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * My Loans (roadmap 13): each loan, cash advance and other company deduction of
 * one person, with what each generated payslip took from it, read from the
 * payslips' own frozen loan lines (deduction_id), so the history always matches
 * what was paid. Cash bond aside (CashBondBalanceQuery): it's kept, not owed.
 *
 * @phpstan-type Payment array{period: string, pay_date: CarbonImmutable, amount: float, balance_after: float|null}
 * @phpstan-type Loan array{deduction: EmployeeDeduction, paid: float, payments: list<Payment>}
 */
final class MyLoansQuery
{
    /**
     * @return list<Loan> active and waiting first, then paused and paid off
     */
    public function handle(User $user): array
    {
        $deductions = EmployeeDeduction::query()->where('user_id', $user->id)->where('type', '!=', DeductionType::CashBond)
            ->orderByDesc('is_active')->orderByDesc('id')->get();

        if ($deductions->isEmpty()) {
            return [];
        }

        /** @var array<int, list<Payment>> $payments */
        $payments = [];

        foreach (PayslipRecord::query()->where('user_id', $user->id)->with('payrollRun')->get() as $record) {
            foreach ($record->loan_lines ?? [] as $line) {
                if (! isset($line['deduction_id']) || (float) $line['amount'] <= 0) {
                    continue;
                }

                $payments[(int) $line['deduction_id']][] = [
                    'period' => $record->payrollRun->periodLabel(),
                    'pay_date' => $record->payrollRun->pay_date,
                    'amount' => (float) $line['amount'],
                    // The balance before this payslip, less what it took.
                    'balance_after' => $line['remaining_balance'] !== null ? max(0.0, (float) $line['remaining_balance'] - (float) $line['amount']) : null,
                ];
            }
        }

        $loans = [];

        foreach ($deductions as $deduction) {
            $mine = $payments[$deduction->id] ?? [];
            usort($mine, fn (array $a, array $b) => $b['pay_date'] <=> $a['pay_date']);

            $paid = 0.0;
            foreach ($mine as $payment) {
                $paid += $payment['amount'];
            }

            $loans[] = ['deduction' => $deduction, 'paid' => $paid, 'payments' => $mine];
        }

        return $loans;
    }
}
