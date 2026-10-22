<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\User;

final class UserPolicy
{
    public function viewAvatar(User $viewer, User $owner): bool
    {
        return $viewer->is($owner)
            || $viewer->can(Ability::SuperviseAttendance->value)
            || $viewer->can(Ability::ViewPeople->value);
    }
}
