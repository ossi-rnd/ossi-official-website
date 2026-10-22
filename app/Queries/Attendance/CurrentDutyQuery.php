<?php

namespace App\Queries\Attendance;

use App\Data\DutyForPunch;
use App\Models\DutyAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculator;
use Carbon\CarbonImmutable;

/**
 * Which duty a clock-in right now belongs to.
 *
 *  1. A rostered duty whose window holds "now": from early_clock_in_minutes
 *     before its start to its end. Yesterday's duty counts too, so a guard
 *     late for a 22:00–07:00 duty can still clock in at 00:30.
 *  2. On the roster (any duty within roster_window_days) but none now:
 *     refused, with the reason (too early, already over, or a day off) and
 *     their next duty.
 *  3. Never rostered: their usual shift from the employee record, today.
 */
final class CurrentDutyQuery
{
    public function __construct(private readonly AttendanceCalculator $calculator) {}

    public function handle(User $user, CarbonImmutable $now): DutyForPunch
    {
        $user->loadMissing(['employeeProfile.shift', 'employeeProfile.client']);
        $profile = $user->employeeProfile;
        $today = $now->startOfDay();
        $early = (int) config('attendance.early_clock_in_minutes');
        $usualShift = $profile->shift ?? Shift::fallback();

        $recent = DutyAssignment::query()
            ->with(['shift', 'client'])
            ->where('user_id', $user->id)
            ->whereDate('duty_date', '>=', $today->subDay()->toDateString())
            ->whereDate('duty_date', '<=', $today->toDateString())
            ->orderByDesc('duty_date')
            ->get();

        foreach ($recent as $duty) {
            [$start, $end] = $this->calculator->shiftWindow($duty->shift, $duty->duty_date);

            if ($now->gte($start->subMinutes($early)) && $now->lte($end)) {
                return new DutyForPunch($duty, $duty->shift, $duty->duty_date, $duty->client ?? $profile?->client);
            }
        }

        $window = (int) config('attendance.roster_window_days');
        $onRoster = $recent->isNotEmpty() || DutyAssignment::query()
            ->where('user_id', $user->id)
            ->whereDate('duty_date', '>=', $today->subDays($window)->toDateString())
            ->whereDate('duty_date', '<=', $today->addDays($window)->toDateString())
            ->exists();

        if (! $onRoster) {
            return new DutyForPunch(null, $usualShift, $today, $profile?->client);
        }

        $next = DutyAssignment::query()->with(['shift', 'client'])
            ->where('user_id', $user->id)
            ->whereDate('duty_date', '>', $today->toDateString())
            ->orderBy('duty_date')
            ->first();

        $todays = $recent->first(fn (DutyAssignment $duty) => $duty->duty_date->isSameDay($today));
        $refusal = 'You have no duty today. If Operations called you in, ask them to add it to the Duty Roster first.';

        if ($todays !== null) {
            [$start, $end] = $this->calculator->shiftWindow($todays->shift, $todays->duty_date);
            $refusal = $now->lt($start)
                ? "Your duty today starts at {$start->format('g:i A')}. You can clock in from {$start->subMinutes($early)->format('g:i A')}."
                : "Your duty today ended at {$end->format('g:i A')}.";
        }

        return new DutyForPunch(null, $usualShift, $today, $profile?->client, $refusal, $next);
    }
}
