<?php

namespace App\Queries\Payroll;

use App\Enums\DeductionType;
use App\Models\CashBondEntry;
use App\Models\EmployeeDeduction;
use App\Models\PayslipRecord;

/**
 * Each person's cash bond, from two sources that never overlap:
 *
 *   collected   the "Cash Bond" lines on their generated payslips (₱100 a cut-off,
 *               or less when the pay couldn't cover it: then it simply wasn't taken)
 *   entries     the ledger: what was paid in before the system (opening), corrections,
 *               and the refund in final pay (negative)
 *
 *   balance = opening + collected + adjustments + refunds
 *
 * Generated payslips count, paid or not: the deduction was made on that payslip.
 *
 * @phpstan-type CashBond array{per_cutoff: float, opening: float, collected: float, adjustments: float, refunded: float, balance: float}
 */
final class CashBondBalanceQuery
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, CashBond> keyed by user_id; everyone asked for, zeros when none
     */
    public function handle(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // One running total per source, keyed by user; read with ?? 0 at the end.
        /** @var array<int, float> $perCutoff */
        $perCutoff = [];
        /** @var array<int, float> $collected */
        $collected = [];
        /** @var array<string, array<int, float>> $entries kind → user → total */
        $entries = [CashBondEntry::OPENING => [], CashBondEntry::ADJUSTMENT => [], CashBondEntry::REFUND => []];

        // The active deduction: how much a cut-off takes.
        foreach (EmployeeDeduction::query()->active()->where('type', DeductionType::CashBond)->whereIn('user_id', $userIds)->get(['user_id', 'installment_amount']) as $deduction) {
            $perCutoff[$deduction->user_id] = ($perCutoff[$deduction->user_id] ?? 0.0) + (float) $deduction->installment_amount;
        }

        // Payslip lines of type cash_bond (stored in loan_lines with the deduction's type).
        foreach (PayslipRecord::query()->whereIn('user_id', $userIds)->whereNotNull('loan_lines')->select(['id', 'user_id', 'loan_lines'])->lazy(500) as $record) {
            foreach ($record->loan_lines ?? [] as $line) {
                if ($line['type'] === DeductionType::CashBond->value) {
                    $collected[$record->user_id] = ($collected[$record->user_id] ?? 0.0) + (float) $line['amount'];
                }
            }
        }

        foreach (CashBondEntry::query()->whereIn('user_id', $userIds)->selectRaw('user_id, kind, SUM(amount) as total')->groupBy('user_id', 'kind')->get() as $entry) {
            $kind = array_key_exists($entry->kind, $entries) ? $entry->kind : CashBondEntry::ADJUSTMENT;
            $entries[$kind][$entry->user_id] = ($entries[$kind][$entry->user_id] ?? 0.0) + (float) $entry->getAttribute('total');
        }

        $rows = [];

        foreach ($userIds as $id) {
            $opening = round($entries[CashBondEntry::OPENING][$id] ?? 0.0, 2);
            $fromPayslips = round($collected[$id] ?? 0.0, 2);
            $adjustments = round($entries[CashBondEntry::ADJUSTMENT][$id] ?? 0.0, 2);
            $refunded = round($entries[CashBondEntry::REFUND][$id] ?? 0.0, 2);

            $rows[$id] = [
                'per_cutoff' => round($perCutoff[$id] ?? 0.0, 2),
                'opening' => $opening,
                'collected' => $fromPayslips,
                'adjustments' => $adjustments,
                'refunded' => $refunded,
                'balance' => round($opening + $fromPayslips + $adjustments + $refunded, 2),
            ];
        }

        return $rows;
    }

    /**
     * @return CashBond
     */
    public function forUser(int $userId): array
    {
        return $this->handle([$userId])[$userId];
    }
}
