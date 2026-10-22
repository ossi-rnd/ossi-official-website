<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\RequestFiled;
use Illuminate\Support\Facades\Notification;

/**
 * Office staff's overtime goes to the President or Vice President account(s) only: bell and
 * email to every active Executive, never to the person filing. A guard's
 * sends nothing here. (Leave, including office staff's, is routed by
 * LeaveRouting, which tells whoever acts next.)
 */
final class NotifyExecutives
{
    public static function of(LeaveRequest|OvertimeRequest $request): void
    {
        $request->loadMissing('user.employeeProfile');

        if (! $request->user->isOfficeStaff()) {
            return;
        }

        $executives = User::query()->notifiable(Role::leadership())->whereKeyNot($request->user_id)->get();

        if ($executives->isNotEmpty()) {
            Notification::send($executives, new RequestFiled($request));
        }
    }
}
