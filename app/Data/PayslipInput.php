<?php

namespace App\Data;

/**
 * Everything the calculator needs for one employee in one run, already
 * gathered from the database by PayslipInputsQuery.
 */
final readonly class PayslipInput
{
    /**
     * @param  int  $nightMinutes  worked 10 PM–6 AM inside the shift (AttendanceCalculator::nightMinutes)
     * @param  int  $overtimeMinutes  already capped per day at the APPROVED request
     * @param  array<string, int>  $holidayDaysWorked  HolidayType value => days worked
     * @param  list<LoanLine>  $loans
     * @param  list<LateBracket>  $lateBrackets  empty = no brackets configured
     * @param  list<CarryoverLine>  $carryovers  owed from earlier payslips that couldn't cover them
     * @param  list<AdjustmentLine>  $adjustments  APPROVED adjustments for this run
     * @param  MonthToDate|null  $monthToDate  earlier payslips this month; null = this run is the whole month
     */
    public function __construct(
        public bool $isOfficeStaff,
        public int $nightMinutes,
        public int $daysWorked,
        public int $lateMinutes,
        public int $overtimeMinutes,
        public array $holidayDaysWorked = [],
        public array $loans = [],
        public array $lateBrackets = [],
        public array $carryovers = [],
        public array $adjustments = [],
        public ?MonthToDate $monthToDate = null,
        // The employee's own rate from HR (employee_rates); null = the schedule's default for their type.
        public ?float $dailyRate = null,
    ) {}
}
