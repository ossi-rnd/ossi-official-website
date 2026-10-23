<?php

namespace App\Enums;

/**
 * What an item on the equipment list is. A uniform is charged to the person, with their
 * authority to deduct (the owner's rule, 2026-10-23). Gear stays company property: the
 * person signs that they're accountable for it, and pays only if it's lost or damaged
 * through their fault, after they were heard.
 */
enum EquipmentKind: string
{
    case Uniform = 'uniform';
    case Gear = 'gear';

    public function label(): string
    {
        return match ($this) {
            self::Uniform => 'Uniform (charged, with their authority to deduct)',
            self::Gear => 'Company property (returned when they leave)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Uniform => 'Uniform',
            self::Gear => 'Company property',
        };
    }
}
