<?php

namespace App\Data;

/**
 * One active company deduction as it applies to one payslip.
 */
final readonly class LoanLine
{
    public function __construct(
        public int $deductionId,
        public string $label,
        public string $type,
        public float $amount,
        public ?float $remainingBalance,
    ) {}

    /**
     * @return array{deduction_id: int, label: string, type: string, amount: float, remaining_balance: float|null}
     */
    public function toArray(): array
    {
        return [
            // Kept so generation reduces the balance by what was ACTUALLY deducted.
            'deduction_id' => $this->deductionId,
            'label' => $this->label,
            'type' => $this->type,
            'amount' => $this->amount,
            'remaining_balance' => $this->remainingBalance,
        ];
    }
}
