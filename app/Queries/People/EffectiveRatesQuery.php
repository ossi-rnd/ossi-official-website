<?php

namespace App\Queries\People;

use App\Data\ResolvedRate;
use App\Enums\RateSource;
use App\Models\EmployeeProfile;
use App\Models\PositionChange;
use App\Services\Payroll\RateScheduleResolver;
use Carbon\CarbonInterface;

/**
 * Each employee's daily rate on a date, the one place that decides it:
 *
 *   1. their personal rate (Daily Rates → Change rate), else
 *   2. the company rate of the position they held ON THAT DATE (Daily Rates →
 *      Rate per position; the position from PositionChange history, so a
 *      promotion counts from its date only), else
 *   3. the schedule's company default for field or office staff.
 */
final class EffectiveRatesQuery
{
    public function __construct(
        private readonly RatesInForceQuery $personal,
        private readonly PositionRatesInForceQuery $positions,
        private readonly RateScheduleResolver $schedules,
    ) {}

    /**
     * @param  iterable<EmployeeProfile>  $profiles
     * @return array<int, ResolvedRate> keyed by employee_profile_id
     */
    public function handle(iterable $profiles, CarbonInterface $date): array
    {
        $list = [];
        foreach ($profiles as $profile) {
            $list[] = $profile;
        }

        $personal = $this->personal->handle(array_map(fn (EmployeeProfile $profile) => $profile->id, $list), $date);
        $positions = $this->positions->handle($date);
        $schedule = $this->schedules->forDate($date);
        $held = PositionChange::positionsOn($list, $date);

        $resolved = [];

        foreach ($list as $profile) {
            $position = $held[$profile->id] ?? null;
            $resolved[$profile->id] = match (true) {
                isset($personal[$profile->id]) => new ResolvedRate($personal[$profile->id], RateSource::Personal),
                $position !== null && isset($positions[$position->value]) => new ResolvedRate($positions[$position->value], RateSource::Position),
                default => new ResolvedRate($schedule->dailyRate($profile->is_office_staff), RateSource::Default),
            };
        }

        return $resolved;
    }

    public function forProfile(EmployeeProfile $profile, CarbonInterface $date): ResolvedRate
    {
        return $this->handle([$profile], $date)[$profile->id];
    }
}
