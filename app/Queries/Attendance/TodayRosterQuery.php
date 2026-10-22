<?php

namespace App\Queries\Attendance;

use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\TimeLog;
use App\Models\User;
use App\Services\Attendance\DayStatusResolver;
use Carbon\CarbonImmutable;

/**
 * Everyone on payroll and their status today. Three queries, whatever the headcount.
 *
 * Where each person is deployed rides along (office, region, province, clientId), so the
 * dashboard can count and filter by area without another query.
 *
 * @phpstan-type RosterRow array{id: int, profileId: int|null, name: string, initials: string, detachment: string|null, post: string|null, shift: string|null, clockIn: string|null, clockOut: string|null, hours: string|null, status: string, clockedIn: bool, office: bool, region: string|null, province: string|null, clientId: int|null}
 */
final class TodayRosterQuery
{
    /**
     * @return list<RosterRow>
     */
    public function handle(?User $viewer = null): array
    {
        $today = CarbonImmutable::today();
        $isHoliday = Holiday::query()->whereDate('date', $today->toDateString())->exists();

        $onLeave = LeaveRequest::query()
            ->approved()
            ->overlapping($today, $today)
            ->pluck('user_id')
            ->flip();

        return array_values(User::query()
            ->onPayroll()
            ->when($viewer, fn ($query, User $viewer) => $query->onDashboardOf($viewer))
            ->with([
                'employeeProfile.shift',
                'employeeProfile.client',
                'timeLogs' => fn ($query) => $query->whereDate('log_date', $today->toDateString()),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($isHoliday, $onLeave): array {
                /** @var TimeLog|null $log */
                $log = $user->timeLogs->first();
                $worked = $log?->workedMinutes();
                $client = $user->employeeProfile?->client;

                return [
                    'id' => $user->id,
                    'profileId' => $user->employeeProfile?->id,
                    'name' => $user->name,
                    'initials' => $user->initials(),
                    // Office staff aren't deployed: "Office" reads better than "—", and is searchable.
                    'detachment' => $user->employeeProfile?->hasDeployment() === false ? 'Office' : $user->employeeProfile?->detachment,
                    'post' => $user->employeeProfile?->hasDeployment() === false ? null : $user->employeeProfile?->post,
                    'shift' => $user->employeeProfile?->shift?->name,
                    'clockIn' => $log?->clock_in_at?->format('g:i A'),
                    'clockOut' => $log?->clock_out_at?->format('g:i A'),
                    'hours' => $worked !== null ? sprintf('%dh %dm', intdiv($worked, 60), $worked % 60) : null,
                    'status' => DayStatusResolver::resolve($log?->status->value, $isHoliday, $onLeave->has($user->id), isToday: true),
                    'clockedIn' => $log?->isOpen() ?? false,
                    'office' => $user->employeeProfile?->hasDeployment() === false,
                    'region' => $client?->region->value,
                    'province' => $client?->province,
                    'clientId' => $client?->id,
                ];
            })
            ->all());
    }
}
