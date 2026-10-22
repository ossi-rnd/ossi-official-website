<?php

namespace App\Actions\People;

use App\Enums\Role;
use App\Models\EmployeeProfile;
use App\Models\SentAlert;
use App\Models\User;
use App\Notifications\BirthdayToday;
use App\Queries\People\BirthdaysQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Run each morning: whose birthday it is today, to HR, and for a guard also to
 * Operation HQ and their region's Satellite Operation. Once per person per year
 * (SentAlert), however often it runs. Day and month only: never the age.
 */
final class SendBirthdayReminders
{
    /**
     * @return int how many birthdays were announced
     */
    public function handle(CarbonImmutable $today): int
    {
        $staff = User::query()->notifiable([Role::Hr, Role::OperationsHq, Role::SatelliteOperations])->with('roles')->get();

        if ($staff->isEmpty()) {
            return 0;
        }

        $sent = 0;

        foreach (BirthdaysQuery::candidates()->get() as $profile) {
            if (! $profile->birthdayIn($today->year)?->isSameDay($today)) {
                continue;
            }

            $recipients = $this->recipients($staff, $profile);

            if ($recipients->isEmpty()) {
                continue;
            }

            $sent += DB::transaction(function () use ($profile, $recipients, $today) {
                if (! SentAlert::claim('birthday.'.$today->year, $profile)) {
                    return 0;
                }

                Notification::send($recipients, new BirthdayToday($profile));

                return 1;
            });
        }

        return $sent;
    }

    /**
     * HR for everyone; Operation HQ and the region's Satellite for a guard. Not the person themselves.
     *
     * @param  Collection<int, User>  $staff
     * @return Collection<int, User>
     */
    private function recipients(Collection $staff, EmployeeProfile $profile): Collection
    {
        $region = $profile->client?->region;

        return $staff->filter(fn (User $user) => $user->hasRole(Role::Hr)
            || (! $profile->is_office_staff && ($user->hasRole(Role::OperationsHq) || ($region !== null && $user->operations_region === $region))))
            ->reject(fn (User $user) => $user->id === $profile->user_id)
            ->values();
    }
}
