<?php

namespace App\Enums;

enum HolidayType: string
{
    case Regular = 'regular';
    case SpecialNonWorking = 'special_non_working';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::SpecialNonWorking => 'Special Non-Working',
        };
    }
}
