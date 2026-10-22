<?php

namespace App\Enums;

/**
 * What office staff spent company money on, out of pocket.
 */
enum ReimbursementCategory: string
{
    case Transportation = 'transportation';
    case Meals = 'meals';
    case Supplies = 'supplies';
    case Communication = 'communication';
    case Fees = 'fees';
    // Roadmap 5: a deployment's cost (a reliever's fare, a transfer, an emergency post).
    case Deployment = 'deployment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Transportation => 'Transportation (fare, Grab, parking, toll)',
            self::Meals => 'Meals on official business',
            self::Supplies => 'Office supplies',
            self::Communication => 'Phone or load for work',
            self::Fees => 'Government or notarial fees',
            self::Deployment => 'Deployment (relief, transfer, emergency post)',
            self::Other => 'Other',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Transportation => 'Transportation',
            self::Meals => 'Meals',
            self::Supplies => 'Supplies',
            self::Communication => 'Phone / load',
            self::Fees => 'Fees',
            self::Deployment => 'Deployment',
            self::Other => 'Other',
        };
    }

    /** The purpose box's example. */
    public function example(): string
    {
        return 'e.g. '.match ($this) {
            self::Transportation => 'Grab to SSS Pasig and back, remitting September contributions',
            self::Meals => 'Lunch with the Makati CBD client during the contract review',
            self::Supplies => 'Printer ink for the HQ office',
            self::Communication => 'Load to call guards during the Ortigas incident',
            self::Fees => 'Notarial fee for the client contract',
            self::Deployment => 'Grab for the reliever from Imus to Metrobank Dasmariñas, night of Oct 14',
            self::Other => 'What it was for, and for whom',
        };
    }
}
