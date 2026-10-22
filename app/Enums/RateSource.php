<?php

namespace App\Enums;

/**
 * Where an employee's daily rate comes from, in the order payroll looks.
 */
enum RateSource: string
{
    case Personal = 'personal';
    case Position = 'position';
    case Default = 'default';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Personal rate',
            self::Position => 'Position rate',
            self::Default => 'Company default',
        };
    }
}
