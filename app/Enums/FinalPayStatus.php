<?php

namespace App\Enums;

/**
 * draft → signing → released; "send back" returns a signing statement to draft.
 *
 *   draft     Accounting prepares it: computed lines plus adjustments
 *   signing   submitted: Accounting signs,
 *             then the President or Vice President, whose signature releases it
 *   released  paid out: frozen, the cash bond refunded, what was owed settled
 */
enum FinalPayStatus: string
{
    case Draft = 'draft';
    case Signing = 'signing';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Being prepared',
            self::Signing => 'Signing',
            self::Released => 'Released',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Signing => 'amber',
            self::Released => 'green',
        };
    }
}
