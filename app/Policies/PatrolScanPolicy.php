<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\PatrolScan;
use App\Models\User;

final class PatrolScanPolicy
{
    /**
     * The camera frame of a checkpoint scan: the guard themselves, or
     * Operations (a Satellite Operation only for its region's guards).
     */
    public function viewPhoto(User $user, PatrolScan $scan): bool
    {
        $scan->loadMissing('user.employeeProfile');

        return $scan->user_id === $user->id
            || ($user->can(Ability::ManagePatrols->value) && $user->canSeeProfile($scan->user->employeeProfile));
    }
}
