<?php

namespace App\Actions\Attendance;

use App\Models\User;
use App\Models\UserDevice;
use Carbon\CarbonImmutable;

/**
 * Which phone a punch came from, called by RecordPunch inside its transaction.
 *
 *  - No phone registered yet: this one becomes theirs (their first clock-in).
 *  - Their phone: noted as used.
 *  - Another phone (or a browser that keeps no cookie): for a GUARD, the punch is
 *    flagged; it is never refused. The company gives guards their phone, so another
 *    one is worth a look; office staff use their own, so theirs is only recorded.
 *
 * The phone is told apart by a random code the app keeps in a cookie on it
 * (ClockPanel), stored here only as its SHA-256.
 */
final class CheckPunchDevice
{
    /**
     * @return array{device_hash: string|null, device_label: string|null, other_device: bool}
     */
    public function handle(User $user, ?string $token, ?string $label, CarbonImmutable $now): array
    {
        $hash = $token !== null && $token !== '' ? UserDevice::hash($token) : null;
        $device = UserDevice::query()->where('user_id', $user->id)->lockForUpdate()->first();

        if ($device === null && $hash !== null) {
            $device = new UserDevice;
            $device->forceFill([
                'user_id' => $user->id,
                'token_hash' => $hash,
                'label' => $label,
                'registered_at' => $now,
                'registered_by' => null,
                'last_used_at' => $now,
            ])->save();

            return ['device_hash' => $hash, 'device_label' => $label, 'other_device' => false];
        }

        $same = $device !== null && $hash !== null && hash_equals($device->token_hash, $hash);

        if ($same) {
            $device->forceFill(['last_used_at' => $now, 'label' => $label ?? $device->label])->save();
        }

        $user->loadMissing('employeeProfile');
        $isGuard = $user->employeeProfile !== null && ! $user->employeeProfile->is_office_staff;

        return ['device_hash' => $hash, 'device_label' => $label, 'other_device' => ! $same && $isGuard];
    }
}
