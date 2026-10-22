<?php

namespace App\Actions\Attendance;

use App\Enums\AuditArea;
use App\Models\TimeLogPunch;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The phone a flagged punch came from becomes the guard's (a replacement company
 * phone): from now on it isn't flagged. Earlier punches keep their flag, as history.
 */
final class SetGuardPhone
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, TimeLogPunch $punch): UserDevice
    {
        $punch->loadMissing('timeLog.user.device');
        $guard = $punch->timeLog->user;

        if (! UserDevice::canManage($actor, $guard)) {
            throw new AuthorizationException('You can\'t change this guard\'s phone.');
        }

        if ($punch->device_hash === null) {
            throw ValidationException::withMessages(['phone' => 'That punch came from a browser that keeps no code, so it can\'t be made their phone. Ask them to clock in again on the new phone.']);
        }

        $old = $guard->device?->label;
        $device = $guard->device ?? new UserDevice;
        $device->forceFill([
            'user_id' => $guard->id,
            'token_hash' => $punch->device_hash,
            'label' => $punch->device_label,
            'registered_at' => CarbonImmutable::now(),
            'registered_by' => $actor->id,
            'last_used_at' => $punch->punched_at,
        ])->save();

        $this->audit->record(AuditArea::Attendance, 'attendance.phone_set', "Made {$punch->device_label} {$guard->name}'s phone", $device,
            ['phone' => $punch->device_label, 'previous_phone' => $old], causer: $actor);

        return $device;
    }
}
