<?php

namespace App\Actions\Relievers;

use App\Enums\AbsenceNoticeStatus;
use App\Enums\AbsenceSource;
use App\Enums\AuditArea;
use App\Enums\Role;
use App\Models\AbsenceNotice;
use App\Models\DutyAssignment;
use App\Models\LeaveRequest;
use App\Models\SentAlert;
use App\Models\TimeLog;
use App\Models\User;
use App\Notifications\NoShowAlert;
use App\Queries\Operations\HoldoverQuery;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Run every 5 minutes. A guard who forgot (or didn't bother) to report an
 * absence must not leave a post empty for hours:
 *
 *  1. A duty on the roster whose grace period is over, with no clock-in, no
 *     notice and no approved leave, becomes a no-show: an absence notice
 *     (source no_show) on the Relievers board, and an alert to Operation HQ
 *     and the region's Satellite Operation. The alert names whoever is still
 *     clocked in at that client, who could stay until a reliever arrives.
 *  2. Any absence still uncovered `attendance.no_show.escalate_after_minutes`
 *     after the grace period, or after it was raised if that was later
 *     (no-show or reported), alerts Operation HQ again, once.
 *
 * A reliever is a guard on a duty too, and can be a no-show. One sent after
 * the shift began counts only `reliever_travel_minutes` after being sent.
 *
 * Only duties on the roster count: a guard never put on it has no "day off"
 * on record, so a missing clock-in can't be told from a rest day.
 * Once a shift has ended there's nothing left to cover, so it's left alone.
 * Safe to run twice: a notice is created once per guard and day (checked
 * under a lock), and the escalation is claimed in sent_alerts.
 */
final class DetectNoShows
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly HoldoverQuery $holdover,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{noShows: int, escalated: int}
     */
    public function handle(CarbonImmutable $now): array
    {
        $operations = User::query()->notifiable([Role::OperationsHq, Role::SatelliteOperations])->with('roles')->get();

        return ['noShows' => $this->detect($now, $operations), 'escalated' => $this->escalate($now, $operations)];
    }

    /**
     * @param  Collection<int, User>  $operations
     */
    private function detect(CarbonImmutable $now, Collection $operations): int
    {
        $duties = DutyAssignment::query()
            ->with(['shift', 'client', 'user'])
            ->whereDate('duty_date', '>=', $now->subDay()->toDateString())
            ->whereDate('duty_date', '<=', $now->toDateString())
            ->whereHas('user', fn ($user) => $user->onPayroll()->fieldStaff())
            ->whereDoesntHave('timeLog')
            ->get();

        $raised = 0;

        foreach ($duties as $duty) {
            [$start, $end] = $this->calculator->shiftWindow($duty->shift, $duty->duty_date);
            $due = $start->addMinutes($duty->shift->grace_minutes);

            // A reliever sent after the start (the duty was saved then) gets time to get there.
            if ($duty->isRelief()) {
                $due = $due->max($duty->updated_at->addMinutes((int) config('attendance.no_show.reliever_travel_minutes')));
            }

            if ($now->lte($due) || $now->gte($end)) {
                continue;
            }

            $notice = $this->raise($duty, $start);

            if ($notice !== null) {
                Notification::send($this->operationsFor($operations, $duty->client?->region), new NoShowAlert($notice, NoShowAlert::DETECTED, $this->holdover->first($notice)));
                $raised++;
            }
        }

        return $raised;
    }

    private function raise(DutyAssignment $duty, CarbonImmutable $start): ?AbsenceNotice
    {
        return DB::transaction(function () use ($duty, $start) {
            // The same lock SubmitAbsenceNotice takes: a guard reporting right now can't race this.
            User::query()->whereKey($duty->user_id)->lockForUpdate()->first();
            $date = $duty->duty_date->toDateString();

            $accountedFor = TimeLog::query()->where('user_id', $duty->user_id)->whereDate('log_date', $date)->exists()
                || AbsenceNotice::query()->where('user_id', $duty->user_id)->whereDate('duty_date', $date)->where('status', '!=', AbsenceNoticeStatus::Cancelled)->exists()
                || LeaveRequest::query()->approved()->where('user_id', $duty->user_id)->overlapping($duty->duty_date, $duty->duty_date)->exists();

            if ($accountedFor) {
                return null;
            }

            $notice = new AbsenceNotice;
            $notice->forceFill([
                'user_id' => $duty->user_id,
                'duty_date' => $date,
                'source' => AbsenceSource::NoShow,
                // A relief duty missed too: the reliever didn't come either.
                'reason' => 'Duty from '.$start->format('g:i A').': '.($duty->isRelief() ? 'sent as a reliever, but didn\'t clock in.' : 'didn\'t clock in or report.'),
                'client_id' => $duty->client_id,
                'detachment' => $duty->client->name ?? null,
                'post' => $duty->post,
                'duty_assignment_id' => $duty->id,
            ])->save();

            $this->audit->record(AuditArea::Operations, 'absence.no_show', "{$duty->user->name} didn't clock in or report for {$duty->placeLabel()} on {$duty->duty_date->format('M j, Y')}", $notice);

            return $notice->setRelation('user', $duty->user)->setRelation('dutyAssignment', $duty);
        });
    }

    /**
     * @param  Collection<int, User>  $operations
     */
    private function escalate(CarbonImmutable $now, Collection $operations): int
    {
        $headquarters = $operations->filter(fn (User $user) => $user->hasRole(Role::OperationsHq))->values();

        if ($headquarters->isEmpty()) {
            return 0;
        }

        $after = (int) config('attendance.no_show.escalate_after_minutes');
        $sent = 0;

        $open = AbsenceNotice::query()
            ->where('status', AbsenceNoticeStatus::Open)
            ->whereDate('duty_date', '>=', $now->subDay()->toDateString())
            ->whereDate('duty_date', '<=', $now->toDateString())
            ->with(['user.employeeProfile.shift', 'client', 'dutyAssignment.shift'])
            ->get();

        foreach ($open as $notice) {
            $window = $notice->dutyWindow();

            // From when the post was known to be empty: the grace end, or later if the absence surfaced later.
            if ($now->lt($window['graceEnd']->max($notice->created_at)->addMinutes($after)) || $now->gte($window['end'])) {
                continue;
            }

            $claimed = DB::transaction(fn () => SentAlert::claim('absence.escalated', $notice));

            if ($claimed) {
                Notification::send($headquarters, new NoShowAlert($notice, NoShowAlert::ESCALATED, $this->holdover->first($notice)));
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Operation HQ always; a Satellite Operation only for its own region.
     *
     * @param  Collection<int, User>  $operations
     * @return Collection<int, User>
     */
    private function operationsFor(Collection $operations, mixed $region): Collection
    {
        return $operations->filter(fn (User $user) => $user->hasRole(Role::OperationsHq) || ($region !== null && $user->operations_region === $region))->values();
    }
}
