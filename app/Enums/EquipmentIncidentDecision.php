<?php

namespace App\Enums;

enum EquipmentIncidentDecision: string
{
    case Charged = 'charged';
    case NotCharged = 'not_charged';

    public function label(): string
    {
        return match ($this) {
            self::Charged => 'Charged',
            self::NotCharged => 'Not charged',
        };
    }
}
