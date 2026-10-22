<?php

namespace App\Data;

/**
 * An approved adjustment as the calculator sees it. Signed amount: positive
 * adds to pay, negative is a deduction.
 */
final readonly class AdjustmentLine
{
    public function __construct(
        public int $adjustmentId,
        public string $label,
        public float $amount,
        public bool $isTaxable,
    ) {}
}
