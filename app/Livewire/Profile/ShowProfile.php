<?php

namespace App\Livewire\Profile;

use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Shift;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Read-only personnel details. HR owns these (they feed payroll and
 * government remittances), so changes go through HR, not self-service.
 */
#[Title('My Profile')]
class ShowProfile extends Component
{
    use InteractsWithUser;

    public function render(): View
    {
        $user = $this->currentUser()->loadMissing(['employeeProfile.shift', 'roles']);

        return view('livewire.profile.show-profile', [
            'user' => $user,
            'profile' => $user->employeeProfile,
            'shift' => $user->employeeProfile->shift ?? Shift::fallback(),
        ]);
    }
}
