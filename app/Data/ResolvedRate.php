<?php

namespace App\Data;

use App\Enums\RateSource;

/**
 * An employee's daily rate on a date, and where it came from.
 */
final readonly class ResolvedRate
{
    public function __construct(
        public float $rate,
        public RateSource $source,
    ) {}
}
