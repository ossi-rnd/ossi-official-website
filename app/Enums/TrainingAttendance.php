<?php

namespace App\Enums;

enum TrainingAttendance: string
{
    case Enrolled = 'enrolled';
    case Completed = 'completed';
    case DidNotComplete = 'did_not_complete';

    public function label(): string
    {
        return match ($this) {
            self::Enrolled => 'Enrolled',
            self::Completed => 'Completed',
            self::DidNotComplete => 'Did not complete',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Enrolled => 'gray',
            self::Completed => 'blue',
            self::DidNotComplete => 'red',
        };
    }
}
