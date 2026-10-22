<?php

namespace App\Actions\Relievers;

use App\Enums\AbsenceNoticeStatus;
use App\Enums\AuditArea;
use App\Enums\Role;
use App\Models\AbsenceNotice;
use App\Models\User;
use App\Notifications\AbsenceNoticeFiled;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A guard says ahead of time they can't report for duty. Operation HQ, and
 * the Satellite Operation of the post's region, are told at once so they
 * can find a reliever.
 *
 * This is NOT leave: it doesn't excuse the day or touch payroll. It only
 * makes sure the post is covered. A planned day off still goes through My Leave.
 */
final class SubmitAbsenceNotice
{
    /** How far ahead a notice may be filed. */
    public const MAX_DAYS_AHEAD = 60;

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $guard, CarbonImmutable $dutyDate, string $reason): AbsenceNotice
    {
        $profile = $guard->employeeProfile()->with('client')->first();

        if ($profile === null || ! $profile->hasDeployment() || ! $profile->employment_status->isActive()) {
            throw ValidationException::withMessages(['dutyDate' => 'Only guards deployed to a post file absence notices. Office staff use My Leave.']);
        }

        $today = CarbonImmutable::today();

        if ($dutyDate->lt($today) || $dutyDate->gt($today->addDays(self::MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages(['dutyDate' => 'Choose today or a day in the next '.self::MAX_DAYS_AHEAD.' days.']);
        }

        return DB::transaction(function () use ($guard, $profile, $dutyDate, $reason) {
            // Locked on the user, so two taps can't file the same day twice.
            User::query()->whereKey($guard->id)->lockForUpdate()->first();

            $existing = AbsenceNotice::query()
                ->where('user_id', $guard->id)
                ->whereDate('duty_date', $dutyDate->toDateString())
                ->where('status', '!=', AbsenceNoticeStatus::Cancelled)
                ->first();

            // Already flagged as a no-show: reporting late still tells Operations why. It stays a no-show.
            if ($existing !== null && $existing->isNoShow() && $existing->status !== AbsenceNoticeStatus::Arrived) {
                return $this->explainNoShow($existing, $guard, $reason);
            }

            if ($existing !== null) {
                throw ValidationException::withMessages(['dutyDate' => 'You already sent a notice for this day.']);
            }

            $notice = new AbsenceNotice;
            $notice->forceFill([
                'user_id' => $guard->id,
                'duty_date' => $dutyDate->toDateString(),
                'reason' => trim($reason),
                'client_id' => $profile->client_id,
                'detachment' => $profile->detachment,
                'post' => $profile->post,
            ])->save();

            $this->audit->record(AuditArea::Operations, 'absence.filed', "{$guard->name} can't report on {$dutyDate->format('M j, Y')}", $notice, causer: $guard);

            Notification::send($this->operations($profile->client?->region), new AbsenceNoticeFiled($notice->setRelation('user', $guard)));

            return $notice;
        });
    }

    private function explainNoShow(AbsenceNotice $notice, User $guard, string $reason): AbsenceNotice
    {
        $notice->forceFill(['reason' => mb_substr($notice->reason.' Reported at '.now()->format('g:i A').': '.trim($reason), 0, 2000)])->save();

        $this->audit->record(AuditArea::Operations, 'absence.no_show_explained', "{$guard->name} reported after being flagged as a no-show for {$notice->duty_date->format('M j, Y')}", $notice, causer: $guard);

        $notice->loadMissing('client');
        Notification::send($this->operations($notice->client?->region), new AbsenceNoticeFiled($notice->setRelation('user', $guard)));

        return $notice;
    }

    /**
     * Operation HQ always; a Satellite Operation only for its own region.
     *
     * @return Collection<int, User>
     */
    private function operations(mixed $region): Collection
    {
        return User::query()
            ->notifiable([Role::OperationsHq, Role::SatelliteOperations])
            ->with('roles')
            ->get()
            ->filter(fn (User $user) => $user->hasRole(Role::OperationsHq) || ($region !== null && $user->operations_region === $region))
            ->values();
    }
}
