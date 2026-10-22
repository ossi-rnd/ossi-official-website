<?php

namespace App\Actions\Overtime;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\RequestFiled;
use App\Support\LeaveRouting;
use App\Support\NotifyExecutives;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An employee asks for overtime. Nobody picks a supervisor: a guard's goes to
 * Operations (Operation HQ and their region's Satellite Operation are told),
 * office staff's to the President or Vice President. Who may decide is still by role
 * (ReviewOvertimeRequest): Operations, HR or Admin for guards, the President or Vice President
 * for office staff.
 */
final class SubmitOvertimeRequest
{
    public function handle(User $user, CarbonImmutable $date, int $requestedMinutes, string $reason, ?string $post): OvertimeRequest
    {
        $duplicate = $user->overtimeRequests()
            ->whereDate('date', $date->toDateString())
            ->whereIn('status', RequestStatus::blocking())
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['date' => 'You already have a pending or approved overtime request for this date.']);
        }

        $overtime = new OvertimeRequest([
            'date' => $date->toDateString(),
            'post' => $post,
            'requested_minutes' => $requestedMinutes,
            'reason' => $reason,
        ]);
        $overtime->user()->associate($user);
        $overtime->save();

        // Office staff's: every Executive. A guard's: Operations.
        NotifyExecutives::of($overtime);

        if (! $user->isOfficeStaff()) {
            Notification::send(self::operationsFor($user), new RequestFiled($overtime));
        }

        return $overtime;
    }

    /**
     * Operation HQ, and the Satellite Operation of the guard's region (if it has one).
     *
     * @return Collection<int, User>
     */
    public static function operationsFor(User $guard): Collection
    {
        $hq = User::query()->notifiable([Role::OperationsHq])->get();
        $region = LeaveRouting::regionOf($guard);
        $satellites = $region !== null ? LeaveRouting::satellitesFor($region) : new Collection;

        return $hq->merge($satellites)->reject(fn (User $user) => $user->id === $guard->id)->values();
    }
}
