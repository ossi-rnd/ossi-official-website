<?php

namespace App\Enums;

enum EquipmentIncidentKind: string
{
    case Damaged = 'damaged';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged',
            self::Lost => 'Lost',
        };
    }
}
