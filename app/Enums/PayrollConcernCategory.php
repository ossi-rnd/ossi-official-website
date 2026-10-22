<?php

namespace App\Enums;

/**
 * What an employee says is wrong on a payslip, in their words, so Accounting
 * knows where to look first.
 */
enum PayrollConcernCategory: string
{
    case MissingDays = 'missing_days';
    case Overtime = 'overtime';
    case Late = 'late';
    case Deductions = 'deductions';
    case Rate = 'rate';
    case Contributions = 'contributions';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MissingDays => 'A day I worked is missing',
            self::Overtime => 'Overtime is missing or wrong',
            self::Late => 'Late deduction is wrong',
            self::Deductions => 'A loan or deduction is wrong',
            self::Rate => 'My daily rate is wrong',
            self::Contributions => 'SSS, PhilHealth, Pag-IBIG or tax',
            self::Other => 'Something else',
        };
    }

    /** The example in the details box. */
    public function example(): string
    {
        return 'e.g. '.match ($this) {
            self::MissingDays => 'I worked Oct 10 at Makati CBD; my phone was broken so I couldn\'t clock in.',
            self::Overtime => 'I stayed until 9 PM on Oct 8, approved by Sgt. Reyes.',
            self::Late => 'I was late 5 minutes on Oct 3, but 30 minutes were deducted.',
            self::Deductions => 'My cash advance was deducted twice.',
            self::Rate => 'My rate should be ₱755 since the new wage order.',
            self::Contributions => 'SSS was deducted but I don\'t see it on my SSS account.',
            self::Other => 'Describe what\'s wrong and which dates.',
        };
    }
}
