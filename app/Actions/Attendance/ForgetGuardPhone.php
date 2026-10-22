<?php

namespace App\Actions\Attendance;

use App\Enums\AuditArea;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Forget a guard's phone (lost, or a new company phone handed out): their next
 * clock-in registers whatever phone they use, without a flag.
 */
final class ForgetGuardPhone
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, User $guard): void
    {
        if (! UserDevice::canManage($actor, $guard)) {
            throw new AuthorizationException('You can\'t change this guard\'s phone.');
        }

        $device = $guard->device()->first();

        if ($device === null) {
            return;
        }

        $this->audit->record(AuditArea::Attendance, 'attendance.phone_forgotten', "Forgot {$guard->name}'s phone ({$device->label}); their next clock-in registers the phone they use", $guard,
            ['phone' => $device->label], causer: $actor);

        $device->delete();
    }
}
