<?php

namespace App\Actions\Attendance;

use App\Enums\Role;
use App\Livewire\Admin\AttendanceLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\SentAlert;
use App\Models\TimeLog;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\Attendance\AttendanceCalculator;
use App\Support\AttendanceAlertRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Run every 5 minutes. Two gaps nobody heard about before (the rules are HR's, AttendanceAlertRules):
 *
 *  1. A missed clock-out. The employee first, some minutes after the shift ended ("clock out
 *     now"); if it's still open later, their head: Operations for a guard (Operation HQ and the
 *     region's Satellite), the President or Vice President for office staff.
 *  2. An office staffer who hasn't clocked in on a work day (not on leave, not a holiday): the
 *     employee first, and one summary bell to the President or Vice President per run, not one
 *     per person. Guards are covered by DetectNoShows (their roster says when they're due).
 *
 * Each alert is claimed in sent_alerts, so it goes once however often this runs.
 */
final class SendAttendanceAlerts
{
    public function __construct(private readonly AttendanceCalculator $calculator) {}

    /**
     * @return array{reminded: int, escalated: int, officeMissing: int}
     */
    public function handle(CarbonImmutable $now): array
    {
        $rules = AttendanceAlertRules::all();
        [$reminded, $escalated] = $this->missedClockOuts($now, $rules);

        return ['reminded' => $reminded, 'escalated' => $escalated, 'officeMissing' => $this->officeNoClockIns($now, $rules)];
    }

    /**
     * @param  array{clock_out_reminder_minutes: int, clock_out_escalate_minutes: int, office_clock_in_reminder_minutes: int, office_work_days: list<int>}  $rules
     * @return array{0: int, 1: int}
     */
    private function missedClockOuts(CarbonImmutable $now, array $rules): array
    {
        $reminded = $escalated = 0;

        $open = TimeLog::query()
            ->with(['shift', 'user.employeeProfile.shift', 'user.employeeProfile.client', 'dutyAssignment.client'])
            ->whereNotNull('clock_in_at')->whereNull('clock_out_at')
            ->whereDate('log_date', '>=', $now->subDays(2)->toDateString())
            ->whereHas('user', fn ($user) => $user->onPayroll())
            ->get();

        foreach ($open as $log) {
            $shift = $log->shift ?? $log->user->employeeProfile?->shift;

            if ($shift === null) {
                continue;
            }

            [, $end] = $this->calculator->shiftWindow($shift, $log->log_date);
            $ended = $end->format('g:i A').' ('.$end->format('D, M j').')';

            if ($now->gte($end->addMinutes($rules['clock_out_reminder_minutes'])) && $this->claim('clock_out.reminder', $log)) {
                UpdateNotice::tell($log->user, null, 'You haven\'t clocked out',
                    "Your shift ended at {$ended}. Clock out now on My Attendance. If you left earlier, tell your supervisor the real time.", route('attendance'), 'clock');
                $reminded++;
            }

            if ($now->gte($end->addMinutes($rules['clock_out_escalate_minutes'])) && $this->claim('clock_out.escalated', $log)) {
                Notification::send($this->headsOf($log), new UpdateNotice("Not clocked out: {$log->user->name}",
                    "Shift ended at {$ended}; still clocked in since ".$log->clock_in_at?->format('g:i A').'. Check with them, then record the real time.',
                    route('attendance-log', ['show' => AttendanceLog::NOT_CLOCKED_OUT]), 'clock-alert'));
                $escalated++;
            }
        }

        return [$reminded, $escalated];
    }

    /**
     * @param  array{clock_out_reminder_minutes: int, clock_out_escalate_minutes: int, office_clock_in_reminder_minutes: int, office_work_days: list<int>}  $rules
     */
    private function officeNoClockIns(CarbonImmutable $now, array $rules): int
    {
        $today = $now->startOfDay();

        if (! in_array($today->isoWeekday(), $rules['office_work_days'], true) || Holiday::query()->whereDate('date', $today->toDateString())->exists()) {
            return 0;
        }

        $onLeave = LeaveRequest::query()->approved()->overlapping($today, $today)->pluck('user_id')->flip();
        $missing = [];

        $staff = User::query()->onPayroll()
            ->whereHas('employeeProfile', fn ($profile) => $profile->where('is_office_staff', true)->whereNotNull('shift_id'))
            ->whereDoesntHave('timeLogs', fn ($logs) => $logs->whereDate('log_date', $today->toDateString()))
            ->with('employeeProfile.shift')
            ->get();

        foreach ($staff as $user) {
            $shift = $user->employeeProfile?->shift;

            if ($shift === null || $onLeave->has($user->id)) {
                continue;
            }

            [$start, $end] = $this->calculator->shiftWindow($shift, $today);

            if ($now->lt($start->addMinutes($shift->grace_minutes + $rules['office_clock_in_reminder_minutes'])) || $now->gte($end)) {
                continue;
            }

            if ($this->claim('office.no_clock_in.'.$today->format('Ymd'), $user)) {
                UpdateNotice::tell($user, null, 'You haven\'t clocked in today',
                    'Your shift started at '.$start->format('g:i A').'. Clock in on My Attendance, or file leave if you\'re off today.', route('attendance'), 'clock');
                $missing[] = $user->name.' ('.$start->format('g:i A').')';
            }
        }

        // One bell for the whole batch to the President or Vice President, who head office staff.
        if ($missing !== []) {
            Notification::send(User::query()->notifiable(Role::leadership())->get(), new UpdateNotice(
                count($missing).' office '.str('staffer')->plural(count($missing)).' not clocked in',
                implode(', ', $missing).'. Not on leave today.', route('attendance-log'), 'clock-alert'));
        }

        return count($missing);
    }

    /**
     * Who heads this person: Operation HQ and the Satellite of their client's region for a
     * guard; the President or Vice President for office staff. Never the person themselves.
     *
     * @return Collection<int, User>
     */
    private function headsOf(TimeLog $log): Collection
    {
        $profile = $log->user->employeeProfile;

        if ($profile === null || $profile->is_office_staff) {
            return User::query()->notifiable(Role::leadership())->whereKeyNot($log->user_id)->get();
        }

        $region = ($log->dutyAssignment->client ?? $profile->client)?->region;

        return User::query()->notifiable([Role::OperationsHq, Role::SatelliteOperations])->with('roles')->whereKeyNot($log->user_id)->get()
            ->filter(fn (User $user) => $user->hasRole(Role::OperationsHq) || ($region !== null && $user->operations_region === $region))
            ->values();
    }

    private function claim(string $alert, TimeLog|User $subject): bool
    {
        return DB::transaction(fn () => SentAlert::claim($alert, $subject));
    }
}
