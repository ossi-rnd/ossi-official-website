<?php

namespace App\Actions\Patrols;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\Role;
use App\Models\AwayLog;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\PatrolAlert;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Run every 15 minutes. An absence nobody ended (no "Back", and no clock-out,
 * which would have ended it) would show as "out" forever. An hour after the
 * duty's scheduled end it is closed at that end and marked auto_closed_at,
 * so the lists say "Never ended: closed at duty end", and their supervisor
 * gets a bell alert (and email) to follow it up.
 */
final class CloseForgottenAways
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return int how many were closed
     */
    public function handle(CarbonImmutable $now): int
    {
        $grace = (int) config('attendance.away.auto_close_after_minutes');
        $closed = 0;

        AwayLog::query()->open()->with(['timeLog.shift', 'timeLog.dutyAssignment.client', 'user.employeeProfile'])->orderBy('id')->each(function (AwayLog $away) use ($now, $grace, &$closed) {
            $dutyEnd = self::dutyEnd($away);

            if ($now->lessThan($dutyEnd->addMinutes($grace))) {
                return;
            }

            $closed += DB::transaction(function () use ($away, $dutyEnd, $now) {
                // Re-read under a lock: they may have tapped "Back" a moment ago.
                $fresh = AwayLog::query()->whereKey($away->id)->open()->lockForUpdate()->first();

                if ($fresh === null) {
                    return 0;
                }

                $fresh->forceFill([
                    'returned_at' => $dutyEnd->greaterThan($fresh->left_at) ? $dutyEnd : $fresh->left_at,
                    'auto_closed_at' => $now,
                ])->save();

                $this->audit->record(AuditArea::Attendance, 'away.auto_closed', "{$away->user->name}'s time away ({$fresh->reason->label()}) was never ended; closed at the duty's end, {$dutyEnd->format('M j g:i A')}", $fresh);

                // Their supervisor follows it up: nobody knows when (or whether) they came back.
                [$recipients, $url] = $this->supervisors($away);
                Notification::send($recipients, new PatrolAlert($away->user->name, 'Time away never ended ('.$fresh->reason->label().')',
                    'Left at '.$fresh->left_at->format('g:i A').', never tapped "Back" or clocked out. Closed at the duty\'s end, '.$dutyEnd->format('g:i A').'. Ask them what happened.', $url));

                return 1;
            });
        });

        return $closed;
    }

    /**
     * The same people who get the other time-away alerts: the President or Vice President for
     * office staff; Operation HQ and the region's Satellite Operation for guards.
     *
     * @return array{0: Collection<int, User>, 1: string}
     */
    private function supervisors(AwayLog $away): array
    {
        if ($away->user->employeeProfile->is_office_staff ?? true) {
            return [User::query()->notifiable(Ability::MonitorOfficeAway->roles())->get(), route('office-away')];
        }

        $region = $away->timeLog->dutyAssignment?->client?->region;
        $operations = User::query()->notifiable([Role::OperationsHq, Role::SatelliteOperations])->with('roles')->get()
            ->filter(fn (User $user) => $user->hasRole(Role::OperationsHq) || ($region !== null && $user->operations_region === $region))
            ->values();

        return [$operations, route('patrols')];
    }

    /** When the duty the absence belongs to was scheduled to end. */
    public static function dutyEnd(AwayLog $away): CarbonImmutable
    {
        $shift = $away->timeLog->shift ?? Shift::fallback();
        $start = $away->timeLog->log_date->setTimeFromTimeString($shift->start_time);

        return $start->addMinutes($shift->scheduledMinutes());
    }
}
