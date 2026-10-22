<?php

namespace App\Actions\Attendance;

use App\Enums\AuditArea;
use App\Enums\Role;
use App\Models\TimeLogPunch;
use App\Models\User;
use App\Notifications\OtherPhoneUsed;
use App\Services\AuditLogger;
use App\Support\LeaveRouting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * A guard punched on a phone that isn't theirs (CheckPunchDevice flagged it).
 * Always in the audit log; the bell goes to Operation HQ, the region's Satellite
 * Operation and HR, once a day per phone (the clock-out on the same phone
 * doesn't ring again).
 */
final class ReportOtherPhone
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(TimeLogPunch $punch): void
    {
        $punch->loadMissing('timeLog.user.device');
        $guard = $punch->timeLog->user;

        $this->audit->record(
            AuditArea::Attendance,
            'attendance.other_phone',
            "{$guard->name} {$punch->type->label()} on another phone ({$this->phone($punch->device_label)})",
            $punch,
            ['phone' => $punch->device_label, 'registered_phone' => $guard->device?->label],
            causer: $guard,
        );

        $alreadyToday = TimeLogPunch::query()
            ->whereKeyNot($punch->id)
            ->where('other_device', true)
            ->whereDate('punched_at', $punch->punched_at->toDateString())
            ->whereHas('timeLog', fn ($log) => $log->where('user_id', $guard->id))
            ->when($punch->device_hash === null, fn ($query) => $query->whereNull('device_hash'), fn ($query) => $query->where('device_hash', $punch->device_hash))
            ->exists();

        if (! $alreadyToday) {
            Notification::send($this->recipients($guard), new OtherPhoneUsed($punch));
        }
    }

    /**
     * Operation HQ, HR, and the Satellite Operation of the guard's region.
     *
     * @return Collection<int, User>
     */
    private function recipients(User $guard): Collection
    {
        $region = LeaveRouting::regionOf($guard);

        return User::query()->notifiable([Role::OperationsHq, Role::SatelliteOperations, Role::Hr])->with('roles')->get()
            ->filter(fn (User $user) => $user->hasRole(Role::OperationsHq) || $user->hasRole(Role::Hr) || ($region !== null && $user->operations_region === $region))
            ->reject(fn (User $user) => $user->is($guard))
            ->values();
    }

    private function phone(?string $label): string
    {
        return $label ?? 'unknown phone';
    }
}
