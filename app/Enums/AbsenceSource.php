<?php

namespace App\Enums;

/**
 * Who raised the absence: the guard ahead of time (Report Absence), or the
 * system when the guard neither clocked in nor reported (DetectNoShows).
 */
enum AbsenceSource: string
{
    case Guard = 'guard';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Guard => 'Reported',
            self::NoShow => 'No-show',
        };
    }
}
