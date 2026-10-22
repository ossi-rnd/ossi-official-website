<?php

namespace App\Enums;

enum PunchType: string
{
    case ClockIn = 'clock_in';
    case ClockOut = 'clock_out';

    public function label(): string
    {
        return match ($this) {
            self::ClockIn => 'Clock In',
            self::ClockOut => 'Clock Out',
        };
    }
}
