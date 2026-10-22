<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\EmployeeProfile;
use App\Models\User;

/**
 * Who may touch a 201 file. HR (manage-people) does everything; the employee
 * may upload their own documents and say when a missing one will come, but
 * never verify anything themselves.
 */
final class EmployeeProfilePolicy
{
    public function viewRequirements(User $user, EmployeeProfile $profile): bool
    {
        return $this->isOwner($user, $profile) || $user->can(Ability::ManagePeople->value);
    }

    public function submitRequirements(User $user, EmployeeProfile $profile): bool
    {
        return $this->viewRequirements($user, $profile);
    }

    public function reviewRequirements(User $user, EmployeeProfile $profile): bool
    {
        return $user->can(Ability::ManagePeople->value);
    }

    private function isOwner(User $user, EmployeeProfile $profile): bool
    {
        return $profile->user_id !== null && $profile->user_id === $user->id;
    }
}
