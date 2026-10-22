<?php

namespace App\Queries\People;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\EmployeeProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Birthdays between two dates (this month, or today), for someone who may see them
 * (Ability::SeeBirthdays): HR everyone; Operations guards only (a Satellite: its region's).
 * Never someone who keeps their birthday private, or who no longer works here.
 *
 * The date of birth is encrypted, so the matching is done here in PHP: at most one
 * row per employee.
 */
final class BirthdaysQuery
{
    /**
     * @return list<array{profile: EmployeeProfile, on: CarbonImmutable}> by date, then name
     */
    public function handle(User $viewer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $viewer->can(Ability::SeeBirthdays->value)) {
            return [];
        }

        $rows = [];
        foreach (self::candidates()->visibleTo($viewer)->when(! $viewer->hasAnyRole(Role::Hr, Role::SuperAdmin), fn (Builder $guards) => $guards->where('is_office_staff', false))->get() as $profile) {
            foreach (array_unique([$from->year, $to->year]) as $year) {
                $on = $profile->birthdayIn($year);
                if ($on !== null && $on->betweenIncluded($from->startOfDay(), $to->startOfDay())) {
                    $rows[] = ['profile' => $profile, 'on' => $on];
                }
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['on'], $a['profile']->name] <=> [$b['on'], $b['profile']->name]);

        return $rows;
    }

    /**
     * Still working here, with a date of birth, not kept private.
     *
     * @return Builder<EmployeeProfile>
     */
    public static function candidates(): Builder
    {
        return EmployeeProfile::query()->active()->where('birthday_private', false)->whereNotNull('birth_date')->with('client');
    }
}
