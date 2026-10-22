<?php

namespace App\Data;

/**
 * What earlier payslips in the same calendar month already counted, so the
 * month's last cutoff can deduct "monthly contribution − already deducted".
 *
 * SSS, PhilHealth and Pag-IBIG are MONTHLY; payroll runs twice a month.
 *
 * @phpstan-type Prior array{base: float, employee: float, employer: float, ec: float}
 */
final readonly class MonthToDate
{
    /**
     * @param  Prior  $sss
     * @param  Prior  $philhealth
     * @param  Prior  $pagibig
     */
    public function __construct(
        public bool $isFinalCutoff,
        public array $sss = ['base' => 0.0, 'employee' => 0.0, 'employer' => 0.0, 'ec' => 0.0],
        public array $philhealth = ['base' => 0.0, 'employee' => 0.0, 'employer' => 0.0, 'ec' => 0.0],
        public array $pagibig = ['base' => 0.0, 'employee' => 0.0, 'employer' => 0.0, 'ec' => 0.0],
    ) {}

    /**
     * A run covering a whole month by itself (or with nothing before it).
     */
    public static function wholeMonth(): self
    {
        return new self(isFinalCutoff: true);
    }
}
