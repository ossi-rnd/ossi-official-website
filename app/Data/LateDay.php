<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * One late day on a payslip: when the shift started, when the employee
 * clocked in, and how many minutes past the grace period that was.
 */
final readonly class LateDay
{
    public function __construct(
        public CarbonImmutable $date,
        public ?string $shiftName,
        public ?string $shiftStart,
        public int $graceMinutes,
        public ?CarbonImmutable $clockInAt,
        public int $lateMinutes,
    ) {}
}
