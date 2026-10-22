<?php

namespace App\Data;

final readonly class LateBracket
{
    public function __construct(
        public int $minMinutes,
        public ?int $maxMinutes,
        public float $amount,
    ) {}

    public function covers(int $lateMinutes): bool
    {
        return $lateMinutes >= $this->minMinutes
            && ($this->maxMinutes === null || $lateMinutes <= $this->maxMinutes);
    }
}
