<?php

namespace App\Data;

/**
 * An amount a previous payslip couldn't cover, owed on this one.
 */
final readonly class CarryoverLine
{
    public function __construct(
        public int $carryoverId,
        public string $label,
        public float $amount,
    ) {}
}
