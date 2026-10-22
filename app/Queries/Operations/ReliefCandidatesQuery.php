<?php

namespace App\Queries\Operations;

use App\Enums\AbsenceNoticeStatus;
use App\Models\AbsenceNotice;
use App\Models\DutyAssignment;
use App\Models\LeaveRequest;
use App\Models\TimeLog;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who can relieve on that day: active guards the viewer may see (a Satellite
 * Operation: its region), other than the absent one, who aren't on approved
 * leave, haven't sent their own absence notice, aren't already relieving
 * someone, and have no duty of their own on the roster that day (a reliever
 * comes from their day off).
 *
 * Nor anyone who wouldn't be rested: still clocked in, or off a shift that
 * ended less than `attendance.no_show.reliever_min_rest_hours` before this
 * duty starts (the night guard going home is no reliever for the day shift).
 *
 * Ordered by how close they are (closeness()): same client, then the same
 * province, then the same region, then everyone else, by name.
 */
final class ReliefCandidatesQuery
{
    public const SAME_SITE = 0;

    public const SAME_PROVINCE = 1;

    public const SAME_REGION = 2;

    public const ELSEWHERE = 3;

    public function __construct(private readonly AttendanceCalculator $calculator) {}

    /**
     * @return Collection<int, User>
     */
    public function handle(AbsenceNotice $notice, User $viewer): Collection
    {
        $date = CarbonImmutable::parse($notice->duty_date);
        $notice->loadMissing('client');

        // Absent that day (their own notice), relieving someone else, or on approved leave.
        $sameDay = AbsenceNotice::query()
            ->whereDate('duty_date', $date->toDateString())
            ->whereNotIn('status', [AbsenceNoticeStatus::Cancelled, AbsenceNoticeStatus::Arrived])
            ->whereKeyNot($notice->id)
            ->get(['user_id', 'reliever_id']);

        $busy = array_values(array_unique([
            $notice->user_id,
            ...$sameDay->pluck('user_id')->all(),
            ...$sameDay->pluck('reliever_id')->filter()->all(),
            ...LeaveRequest::query()->approved()->overlapping($date, $date)->pluck('user_id')->all(),
            // On duty that day already; this notice's own relief duty doesn't count.
            ...DutyAssignment::query()->whereDate('duty_date', $date->toDateString())
                ->where(fn ($query) => $query->whereNull('absence_notice_id')->orWhere('absence_notice_id', '!=', $notice->id))
                ->pluck('user_id')->all(),
            ...$this->notRested($notice, $date),
        ]));

        return User::query()
            ->onPayroll()
            ->visibleTo($viewer)
            ->whereHas('employeeProfile', fn ($profile) => $profile->where('is_office_staff', false))
            ->whereNotIn('id', $busy)
            ->with('employeeProfile.client')
            ->orderBy('name')
            ->get()
            ->sortBy(fn (User $user) => self::closeness($user, $notice))
            ->values();
    }

    /** SAME_SITE, SAME_PROVINCE, SAME_REGION or ELSEWHERE: from the guard's usual client to the post to cover. */
    public static function closeness(User $user, AbsenceNotice $notice): int
    {
        $theirs = $user->employeeProfile?->client;
        $post = $notice->client;

        return match (true) {
            $theirs === null || $post === null => self::ELSEWHERE,
            $theirs->id === $post->id => self::SAME_SITE,
            $theirs->region === $post->region && $theirs->province === $post->province => self::SAME_PROVINCE,
            $theirs->region === $post->region => self::SAME_REGION,
            default => self::ELSEWHERE,
        };
    }

    /**
     * Still on duty, or off a shift too recently to work this one.
     *
     * @return list<int>
     */
    private function notRested(AbsenceNotice $notice, CarbonImmutable $date): array
    {
        $start = $notice->dutyWindow()['start'];
        $restedSince = $start->subHours((int) config('attendance.no_show.reliever_min_rest_hours'));

        $clocked = TimeLog::query()
            ->whereNotNull('clock_in_at')
            ->where('clock_in_at', '<', $start)
            ->where(fn ($log) => $log
                ->where(fn ($open) => $open->whereNull('clock_out_at')->whereDate('log_date', '>=', $date->subDay()->toDateString()))
                ->orWhere('clock_out_at', '>', $restedSince))
            ->pluck('user_id');

        // The roster the day before (for a day still to come, nobody has clocked anything yet).
        $rostered = DutyAssignment::query()->with('shift')->whereDate('duty_date', $date->subDay()->toDateString())->get()
            ->filter(fn (DutyAssignment $duty) => $this->calculator->shiftWindow($duty->shift, $duty->duty_date)[1]->gt($restedSince))
            ->pluck('user_id');

        return array_values($clocked->merge($rostered)->map(fn ($id) => (int) $id)->unique()->all());
    }
}
