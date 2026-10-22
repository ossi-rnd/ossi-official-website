<?php

namespace App\Data;

use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * What a clock-in right now would be for: the rostered duty (or the usual
 * shift when the guard isn't on the roster), the day it counts for, and the
 * post whose geofence applies. `refusal` explains why clocking in isn't
 * allowed now (off duty, too early, duty over).
 */
final readonly class DutyForPunch
{
    public function __construct(
        public ?DutyAssignment $duty,
        public Shift $shift,
        public CarbonImmutable $dutyDate,
        public ?Client $site,
        public ?string $refusal = null,
        public ?DutyAssignment $next = null,
    ) {}

    public function isRostered(): bool
    {
        return $this->duty !== null;
    }
}
