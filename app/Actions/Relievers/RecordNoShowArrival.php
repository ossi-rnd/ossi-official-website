<?php

namespace App\Actions\Relievers;

use App\Enums\AbsenceNoticeStatus;
use App\Enums\AbsenceSource;
use App\Enums\AuditArea;
use App\Enums\Role;
use App\Models\AbsenceNotice;
use App\Models\TimeLog;
use App\Models\User;
use App\Notifications\NoShowAlert;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Notification;

/**
 * Called by RecordPunch on a clock-in. A guard flagged as a no-show turned
 * up after all:
 *  - nobody sent yet: the no-show closes ("Arrived late") and Operations
 *    stops looking for a reliever;
 *  - a reliever already sent: it stays covered, and Operations is told so
 *    they decide who stays.
 * The notice stays on record either way: they still didn't come on time or report.
 */
final class RecordNoShowArrival
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(TimeLog $log): ?AbsenceNotice
    {
        $notice = AbsenceNotice::query()
            ->with(['user', 'client', 'reliever', 'dutyAssignment.shift'])
            ->where('user_id', $log->user_id)
            ->whereDate('duty_date', $log->log_date->toDateString())
            ->where('source', AbsenceSource::NoShow)
            ->whereIn('status', [AbsenceNoticeStatus::Open, AbsenceNoticeStatus::Covered])
            ->whereNull('arrived_at')
            ->lockForUpdate()
            ->first();

        if ($notice === null) {
            return null;
        }

        $covered = $notice->status === AbsenceNoticeStatus::Covered;
        $notice->forceFill([
            'arrived_at' => $log->clock_in_at,
            'status' => $covered ? AbsenceNoticeStatus::Covered : AbsenceNoticeStatus::Arrived,
        ])->save();

        $this->audit->record(AuditArea::Operations, 'absence.arrived', "{$notice->user->name} clocked in at {$log->clock_in_at?->format('g:i A')} after being flagged as a no-show", $notice, causer: $notice->user);

        $region = $notice->client?->region;
        $operations = User::query()->notifiable([Role::OperationsHq, Role::SatelliteOperations])->with('roles')->get()
            ->filter(fn (User $user) => $user->hasRole(Role::OperationsHq) || ($region !== null && $user->operations_region === $region))
            ->values();

        Notification::send($operations, new NoShowAlert($notice, $covered ? NoShowAlert::ARRIVED_COVERED : NoShowAlert::ARRIVED));

        return $notice;
    }
}
