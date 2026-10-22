<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\DailyRateNeeded;
use Illuminate\Support\Facades\Notification;

/**
 * HR's "Add employee". HR doesn't set pay: when no rate applies yet (no
 * company rate for their position), Accounting is told to set one.
 */
final class AddEmployee
{
    public function __construct(private readonly SaveEmployeeProfile $saveProfile) {}

    /**
     * @param  array<string, mixed>  $data  validated fillable attributes
     */
    public function handle(array $data, ?Shift $shift, User $actor): EmployeeProfile
    {
        $profile = $this->saveProfile->handle(null, $data, $shift, $actor);

        if (EmployeeProfile::query()->whereKey($profile->id)->withoutRate()->exists()) {
            Notification::send(User::query()->notifiable(Ability::ManagePayroll->roles())->get(), new DailyRateNeeded($profile));
        }

        return $profile;
    }
}
