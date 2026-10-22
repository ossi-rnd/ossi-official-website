<?php

namespace App\Support;

use App\Enums\LeaveStage;
use App\Enums\LeaveType;
use App\Enums\Region;
use App\Enums\Role;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveAwaitingYou;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Who a leave request goes to next.
 *
 *   office staff → Executive (decides)
 *   guard        → Satellite Operation of the guard's region (endorses)
 *                → Operation HQ (endorses)
 *                → HR (decides, final)
 *
 * No Satellite account for the region (or a guard with no client yet):
 * it starts at Operation HQ.
 */
final class LeaveRouting
{
    public static function firstStage(User $requester, ?LeaveType $type = null): LeaveStage
    {
        if ($requester->isOfficeStaff()) {
            return LeaveStage::Executive;
        }

        // Confidential (VAWC, RA 9262): straight to HR, who decides; no endorsers see it.
        if ($type?->isConfidential()) {
            return LeaveStage::Hr;
        }

        $region = self::regionOf($requester);

        return $region !== null && self::satellitesFor($region)->isNotEmpty() ? LeaveStage::Satellite : LeaveStage::OperationsHq;
    }

    /** The region of the client the guard is deployed to. */
    public static function regionOf(User $guard): ?Region
    {
        $guard->loadMissing('employeeProfile.client');

        return $guard->employeeProfile?->client?->region;
    }

    /**
     * @return Collection<int, User>
     */
    public static function satellitesFor(Region $region): Collection
    {
        return User::query()->notifiable([Role::SatelliteOperations])->where('operations_region', $region->value)->get();
    }

    /**
     * Who acts at this stage of this request (never the person who filed it).
     *
     * @return Collection<int, User>
     */
    public static function actorsFor(LeaveRequest $leave, LeaveStage $stage): Collection
    {
        $actors = match ($stage) {
            LeaveStage::Satellite => ($region = self::regionOf($leave->user)) !== null ? self::satellitesFor($region) : new Collection,
            LeaveStage::OperationsHq => User::query()->notifiable([Role::OperationsHq])->get(),
            LeaveStage::Hr => User::query()->notifiable([Role::Hr])->get(),
            LeaveStage::Executive => User::query()->notifiable(Role::leadership())->get(),
        };

        return $actors->reject(fn (User $user) => $user->id === $leave->user_id)->values();
    }

    /** Bell and email to whoever acts next on this pending request. */
    public static function notifyNext(LeaveRequest $leave): void
    {
        if ($leave->stage === null) {
            return;
        }

        $actors = self::actorsFor($leave, $leave->stage);

        if ($actors->isNotEmpty()) {
            Notification::send($actors, new LeaveAwaitingYou($leave));
        }
    }
}
