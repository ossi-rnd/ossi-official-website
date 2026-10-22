<?php

namespace App\Policies;

use App\Models\EmployeeRequirement;
use App\Models\User;

final class EmployeeRequirementPolicy
{
    public function viewDocument(User $user, EmployeeRequirement $record): bool
    {
        return $user->can('viewRequirements', $record->loadMissing('profile')->profile);
    }
}
