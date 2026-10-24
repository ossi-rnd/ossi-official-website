<?php

namespace App\Queries\People;

use App\Enums\EmploymentStatus;
use App\Models\EmployeeProfile;
use App\Support\ProbationRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Probationary employees whose probation ends within the reminder window,
 * or has already ended without a decision.
 */
final class ProbationEndingQuery
{
    /**
     * @return Collection<int, EmployeeProfile>
     */
    public function handle(CarbonImmutable $today): Collection
    {
        // ends_on = hire_date + months, and we want ends_on - remind_days <= today.
        $latestHireDate = $today
            ->addDays(ProbationRules::remindDaysBefore())
            ->subMonthsNoOverflow(ProbationRules::months());

        return EmployeeProfile::query()
            ->with('user')
            ->where('employment_status', EmploymentStatus::Probationary)
            ->whereNotNull('hire_date')
            ->whereDate('hire_date', '<=', $latestHireDate->toDateString())
            ->orderBy('hire_date')
            ->get();
    }

    public static function endsOn(EmployeeProfile $profile): ?CarbonImmutable
    {
        return ProbationRules::endsOn($profile);
    }
}
