<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\TimeLogPunch;
use App\Models\User;

final class TimeLogPunchPolicy
{
    public function viewPhoto(User $user, TimeLogPunch $punch): bool
    {
        $punch->loadMissing('timeLog');

        return $punch->timeLog->user_id === $user->id
            || ($user->can(Ability::SuperviseAttendance->value) && $user->canSeeProfile($punch->timeLog->user->employeeProfile));
    }
}
