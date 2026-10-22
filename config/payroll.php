<?php

/**
 * Pay rates and statutory amounts, keyed by the date each set took effect.
 *
 * A payroll run uses the schedule in force on its START DATE, never today's,
 * so reopening an old run reproduces the figures it was computed with. See
 * App\Services\Payroll\RateScheduleResolver.
 *
 * To change a rate: ADD a new entry keyed by the date it takes effect.
 * Never edit or delete a past entry — older entries are what keep historical
 * payslips reproducible.
 */
return [

    'schedules' => [

        '1970-01-01' => [
            'daily_rate' => ['field' => 610, 'office' => 900],
            'sss' => ['field' => 581, 'office' => 900],
            'philhealth' => ['field' => 225, 'office' => 400],
            'pagibig' => ['field' => 100, 'office' => 100],

            // Office staff only. Field staff are minimum wage earners, exempt under RA 9504.
            'withholding_rate' => ['field' => 0.0, 'office' => 0.02],
        ],

        /*
         * From 2026: contributions and tax are COMPUTED from the government
         * rules instead of fixed amounts (verified 2026-10-09; have Accounting
         * check them against the circulars before the first live payroll):
         *
         *   SSS        RA 11199: 15% of the Monthly Salary Credit (5% employee,
         *              10% employer), MSC ₱5,000–₱35,000 in ₱500 steps, + EC.
         *   PhilHealth RA 11223: 5% of monthly basic salary, split 2.5% / 2.5%,
         *              floor ₱10,000, ceiling ₱100,000.
         *   Pag-IBIG   RA 9679 / HDMF Circular 460: 2% each (employee 1% at
         *              ₱1,500 or less), Maximum Fund Salary ₱10,000.
         *   Tax        RR 11-2018 semi-monthly table, rates effective 2023.
         *
         * These are MONTHLY contributions: the 1st–15th payslip deducts half,
         * the month's last payslip deducts the rest based on the actual month.
         */
        '2026-01-01' => [
            // No made-up default: a newly onboarded employee has NO rate (₱0,
            // shown as "Not set") until Accounting sets their position's rate or a
            // personal one on Daily Rates. Payroll refuses to generate a payslip
            // without a rate. The 1970 schedule keeps ₱610/₱900 for old payslips.
            'daily_rate' => ['field' => 0, 'office' => 0],

            'statutory' => [
                'sss' => [
                    'employee_rate' => 0.05,
                    'employer_rate' => 0.10,
                    'msc_min' => 5000,
                    'msc_max' => 35000,
                    'msc_step' => 500,
                    // Employees' Compensation (employer only): ₱10 below MSC ₱15,000, ₱30 from it.
                    'ec_low' => 10,
                    'ec_high' => 30,
                    'ec_high_from_msc' => 15000,
                ],
                'philhealth' => [
                    'rate' => 0.05,
                    'floor' => 10000,
                    'ceiling' => 100000,
                ],
                'pagibig' => [
                    'employee_rate' => 0.02,
                    'employee_rate_low' => 0.01,
                    'low_threshold' => 1500,
                    'employer_rate' => 0.02,
                    'max_fund_salary' => 10000,
                ],
                // Semi-monthly withholding: over ₱X → base + rate × (taxable − X).
                'withholding_semi_monthly' => [
                    ['over' => 0, 'base' => 0, 'rate' => 0],
                    ['over' => 10417, 'base' => 0, 'rate' => 0.15],
                    ['over' => 16667, 'base' => 937.50, 'rate' => 0.20],
                    ['over' => 33333, 'base' => 4270.70, 'rate' => 0.25],
                    ['over' => 83333, 'base' => 16770.70, 'rate' => 0.30],
                    ['over' => 333333, 'base' => 91770.70, 'rate' => 0.35],
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Minimum wage (NCR, non-agriculture), by the date each wage order took effect
    |--------------------------------------------------------------------------
    |
    | Decides who is a minimum wage earner (tax exempt) and warns Accounting
    | when a configured daily rate is below the law. Add a line per new wage
    | order; never edit a past one.
    |
    */

    'minimum_wages' => [
        '2025-07-17' => ['amount' => 695, 'wage_order' => 'NCR-26'],
        '2026-09-26' => ['amount' => 755, 'wage_order' => 'NCR-28'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Statutory multipliers (Labor Code)
    |--------------------------------------------------------------------------
    |
    | Holiday premiums are paid ON TOP of basic pay, because basic pay already
    | covers every day with a time log: a worked regular holiday totals 200%,
    | a worked special non-working day 130%. An unworked holiday earns nothing.
    |
    */

    'holiday_premiums' => [
        'regular' => 1.00,
        'special_non_working' => 0.30,
    ],

    'night_differential_rate' => 0.10,

    'overtime_multiplier' => 1.25,

    'standard_hours_per_day' => 8,

    // Printed under every payslip. Corrections never rewrite a past payslip;
    // they are added as lines on the next payroll.
    /*
     * Payslip adjustments (corrections, allowances, refunds, penalties).
     *
     * Accounting has full control of payroll, so adjustments apply at once
     * (decided 2026-10-09). Every one is audited either way.
     *
     * Optional second-person check: set an amount (e.g. 5000) and adjustments
     * of that size or larger wait for someone with approve-payroll-adjustments
     * who isn't their creator. null = off.
     */
    'adjustments' => [
        'approval_threshold' => env('PAYROLL_ADJUSTMENT_APPROVAL_THRESHOLD') ?: null,
    ],

    /*
     * Corrections from earlier cut-offs: before payslips are generated, every
     * paid cut-off that ended within this many days is recomputed with today's
     * attendance, overtime, holidays and rates. A difference is suggested on the
     * open run for Accounting to accept or dismiss (DetectPriorPeriodCorrections).
     */
    'prior_period' => [
        'lookback_days' => 90,
    ],

    /*
     * "Report a problem" on a payslip: the optional photo or PDF is kept on this
     * PRIVATE disk (never a public URL), like load receipts.
     */
    'concerns' => [
        'disk' => env('PAYROLL_CONCERNS_DISK') ?: env('ATTENDANCE_PHOTO_DISK', 'local'),
    ],

    /*
     * Reimbursements and deployment claims: company policy, not law. Only the
     * STARTING values: Accounting sets them on the Reimbursements page (Limits),
     * saved as the "reimbursements" Setting and audited (Reimbursement::limits()).
     */
    'reimbursements' => [
        'file_within_days' => 60,
        'max_amount' => 20000,
        'deployment_max_amount' => 20000,
    ],

    'disclaimer' => 'Payroll figures are subject to change with prior notice. Any adjustment will be reflected in the next payroll.',

];
