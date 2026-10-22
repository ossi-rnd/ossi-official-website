<?php

namespace App\Enums;

/**
 * draft (computed, can be recomputed) → approved (frozen, employees see it) → paid.
 */
enum ThirteenthMonthStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';

    public function next(): ?self
    {
        return match ($this) {
            self::Draft => self::Approved,
            self::Approved => self::Paid,
            self::Paid => null,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Approved or paid: the amounts are final and count as already paid for later runs of the year.
     */
    public function isFinal(): bool
    {
        return $this !== self::Draft;
    }
}
