<?php

namespace App\Queries\Attendance;

use App\Enums\AbsenceNoticeStatus;
use App\Models\AbsenceNotice;
use App\Models\AwayLog;
use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Services\Attendance\AttendanceCalculator;
use App\Support\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who is at a client's posts right now, so Operations can see a missing
 * guard and send a reliever. One row per guard deployed there, in a few
 * bulk queries (no N+1), plus the relievers covering the site today.
 *
 * "Today" is the guard's current duty day: before a night shift's end
 * (e.g. 03:00 on a 22:00–06:00 shift) that's yesterday's shift.
 *
 * States, in this order (a real clock-in always wins):
 *   away       – clocked in but left the post (reason, minutes; red past the limit)
 *   on_duty, clocked_out  – from the time log (red when a patrol round was missed)
 *   absent_covered / absent_open – an absence notice for the day
 *   off        – on the roster, but no duty today
 *   on_leave, holiday
 *   upcoming   – the shift hasn't started
 *   not_in     – started, no clock-in: "Not in yet" within grace,
 *                "No clock-in" after it, "Absent · no clock-in" once the shift ended
 *
 * @phpstan-type GuardToday array{state: string, label: string, detail: string|null, tone: string, attention: bool}
 * @phpstan-type Relief array{reliever: string, for: string, post: string|null}
 */
final class ClientAttendanceTodayQuery
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly PatrolRoundsQuery $patrolRounds,
    ) {}

    /**
     * @param  Collection<int, EmployeeProfile>  $guards  the client's guards, with `shift` loaded
     * @return array{rows: array<int, GuardToday>, relief: list<Relief>}
     */
    public function handle(Client $client, Collection $guards, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $now->startOfDay();
        $fallback = Shift::fallback();

        $userIds = $guards->pluck('user_id')->filter()->values();
        $from = $today->subDay();
        $window = (int) config('attendance.roster_window_days');

        // The roster decides the schedule: yesterday's or today's duty, whichever is running.
        $duties = DutyAssignment::query()->with('shift')->whereIn('user_id', $userIds)
            ->whereDate('duty_date', '>=', $from->toDateString())->whereDate('duty_date', '<=', $today->toDateString())
            ->get()
            ->keyBy(fn (DutyAssignment $duty) => $duty->user_id.'|'.$duty->duty_date->toDateString());

        $onRoster = DutyAssignment::query()->whereIn('user_id', $userIds)
            ->whereDate('duty_date', '>=', $today->subDays($window)->toDateString())->whereDate('duty_date', '<=', $today->addDays($window)->toDateString())
            ->distinct()->pluck('user_id')->flip();

        // Each guard's [duty day, shift], or null for a rostered guard with no duty (a day off).
        $schedules = $guards->mapWithKeys(function (EmployeeProfile $guard) use ($today, $from, $now, $fallback, $duties, $onRoster) {
            $yesterdays = $duties->get($guard->user_id.'|'.$from->toDateString());
            $todays = $duties->get($guard->user_id.'|'.$today->toDateString());

            if ($yesterdays !== null && $now->lt($this->calculator->shiftWindow($yesterdays->shift, $from)[1])) {
                return [$guard->id => [$from, $yesterdays->shift]];
            }

            if ($todays !== null) {
                return [$guard->id => [$today, $todays->shift]];
            }

            if ($guard->user_id !== null && $onRoster->has($guard->user_id)) {
                return [$guard->id => null];
            }

            // Not on the roster: their usual shift, counting last night's while it runs.
            $shift = $guard->shift ?? $fallback;
            [, $end] = $this->calculator->shiftWindow($shift, $from);

            return [$guard->id => [$shift->crossesMidnight() && $now->lt($end) ? $from : $today, $shift]];
        });

        $logs = TimeLog::query()->whereIn('user_id', $userIds)->between($from, $today)->get()
            ->keyBy(fn (TimeLog $log) => $log->user_id.'|'.$log->log_date->toDateString());

        $notices = AbsenceNotice::query()->whereIn('user_id', $userIds)
            ->whereDate('duty_date', '>=', $from->toDateString())->whereDate('duty_date', '<=', $today->toDateString())
            ->whereNotIn('status', [AbsenceNoticeStatus::Cancelled, AbsenceNoticeStatus::Arrived])
            ->with('reliever:id,name')
            ->get()
            ->keyBy(fn (AbsenceNotice $notice) => $notice->user_id.'|'.$notice->duty_date->toDateString());

        $leaves = LeaveRequest::query()->approved()->whereIn('user_id', $userIds)->overlapping($from, $today)->get(['user_id', 'start_date', 'end_date']);

        $holidays = Holiday::query()->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $today->toDateString())
            ->pluck('date')->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())->flip();

        $away = AwayLog::query()->open()->with('latestLocation')->whereIn('user_id', $userIds)->get()->keyBy('user_id');

        $rows = [];

        foreach ($guards as $guard) {
            $schedule = $schedules[$guard->id];

            if ($schedule === null) {
                $rows[$guard->id] = ['state' => 'off', 'label' => 'Day off', 'detail' => 'Not on the roster today', 'tone' => 'gray', 'attention' => false];

                continue;
            }

            /** @var CarbonImmutable $day */
            [$day, $shift] = $schedule;
            $key = $guard->user_id.'|'.$day->toDateString();
            [$start, $end] = $this->calculator->shiftWindow($shift, $day);

            $log = $guard->user_id === null ? null : $logs->get($key);

            $rows[$guard->id] = $this->state(
                $log,
                $guard->user_id === null ? null : $notices->get($key),
                $leaves->contains(fn (LeaveRequest $leave) => $leave->user_id === $guard->user_id && $leave->start_date->lte($day) && $leave->end_date->gte($day)),
                $holidays->has($day->toDateString()),
                $start,
                $start->addMinutes($shift->grace_minutes),
                $end,
                $now,
                $guard->user_id === null ? null : $away->get($guard->user_id),
                $log?->isOpen() ? collect($this->patrolRounds->handle($log, $now)['rounds'])->where('state', 'missed')->count() : 0,
            );
        }

        $relief = array_values(AbsenceNotice::query()
            ->where('client_id', $client->id)
            ->where('status', AbsenceNoticeStatus::Covered)
            ->whereDate('duty_date', $today->toDateString())
            ->with(['reliever:id,name', 'user:id,name'])
            ->get()
            ->map(fn (AbsenceNotice $notice) => ['reliever' => $notice->reliever->name ?? '—', 'for' => $notice->user->name, 'post' => $notice->post])
            ->all());

        return ['rows' => $rows, 'relief' => $relief];
    }

    /**
     * @return GuardToday
     */
    private function state(?TimeLog $log, ?AbsenceNotice $notice, bool $onLeave, bool $holiday, CarbonImmutable $start, CarbonImmutable $graceEnd, CarbonImmutable $end, CarbonImmutable $now, ?AwayLog $away = null, int $missedRounds = 0): array
    {
        $at = fn (?CarbonImmutable $time) => $time?->format('g:i A');

        return match (true) {
            $log?->isOpen() === true && $away !== null => [
                'state' => 'away',
                'label' => 'Away · '.$away->reason->label(),
                'detail' => 'Since '.$at($away->left_at).' · back by '.$at($away->expectedBackAt()).($away->note ? ' · '.$away->note : '').($away->approved_at ? ' · approved' : '').($away->isLocationOverdue($now) ? ' · location overdue' : ''),
                'tone' => $away->needsAttention($now) ? 'red' : 'amber',
                'attention' => $away->needsAttention($now),
            ],
            $log?->isOpen() === true && $missedRounds > 0 => [
                'state' => 'on_duty',
                'label' => 'On duty · round missed',
                'detail' => "{$missedRounds} patrol ".str('round')->plural($missedRounds).' missed today',
                'tone' => 'red',
                'attention' => true,
            ],
            $log?->clock_in_at !== null && $log->clock_out_at === null => [
                'state' => 'on_duty',
                'label' => 'On duty',
                'detail' => 'In at '.$at($log->clock_in_at).($log->late_minutes > 0 ? ' · '.Duration::minutes($log->late_minutes).' late' : ''),
                'tone' => $log->late_minutes > 0 ? 'amber' : 'blue',
                'attention' => false,
            ],
            $log?->clock_in_at !== null => ['state' => 'clocked_out', 'label' => 'Clocked out', 'detail' => $at($log->clock_in_at).' – '.$at($log->clock_out_at), 'tone' => 'gray', 'attention' => false],
            $notice !== null && $notice->status === AbsenceNoticeStatus::Covered => ['state' => 'absent_covered', 'label' => ($notice->isNoShow() ? 'No-show' : 'Absent').' · covered', 'detail' => 'Reliever: '.($notice->reliever->name ?? '—'), 'tone' => 'blue', 'attention' => false],
            $notice !== null && $notice->isNoShow() => ['state' => 'absent_open', 'label' => 'No-show · needs reliever', 'detail' => 'No clock-in and no notice; Operations was alerted', 'tone' => 'red', 'attention' => true],
            $notice !== null => ['state' => 'absent_open', 'label' => 'Absent · needs reliever', 'detail' => 'Sent notice: '.$notice->reason, 'tone' => 'red', 'attention' => true],
            $onLeave => ['state' => 'on_leave', 'label' => 'On leave', 'detail' => null, 'tone' => 'gray', 'attention' => false],
            $holiday => ['state' => 'holiday', 'label' => 'Holiday', 'detail' => null, 'tone' => 'gray', 'attention' => false],
            $now->lt($start) => ['state' => 'upcoming', 'label' => 'Shift not started', 'detail' => 'Starts '.$at($start), 'tone' => 'gray', 'attention' => false],
            $now->gte($end) => ['state' => 'not_in', 'label' => 'Absent · no clock-in', 'detail' => 'Shift ended '.$at($end).' with no notice', 'tone' => 'red', 'attention' => true],
            $now->gt($graceEnd) => ['state' => 'not_in', 'label' => 'No clock-in', 'detail' => 'Shift started '.$at($start).', grace over', 'tone' => 'red', 'attention' => true],
            default => ['state' => 'not_in', 'label' => 'Not in yet', 'detail' => 'Shift started '.$at($start).', within grace', 'tone' => 'amber', 'attention' => false],
        };
    }
}
