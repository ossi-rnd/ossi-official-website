<?php

namespace App\Actions\Roster;

use App\Enums\AuditArea;
use App\Models\DutyAssignment;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fills the EMPTY days of a week for these guards, so Operations doesn't
 * click 7 × 40 cells. Days already set, days that have passed, and relief
 * duties are never touched.
 *
 *  - 'copy':  last week's duties, a week later (shift, client, post).
 *  - 'usual': Monday to Saturday on each guard's usual shift and post; Sunday off.
 *             A "Roving" post becomes a patrol duty.
 */
final class FillRosterWeek
{
    public const COPY = 'copy';

    public const USUAL = 'usual';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  Collection<int, User>  $guards
     * @return int how many duties were created
     */
    public function handle(Collection $guards, CarbonImmutable $weekStart, string $mode, User $actor): int
    {
        if (! in_array($mode, [self::COPY, self::USUAL], true)) {
            throw ValidationException::withMessages(['fill' => 'Unknown way to fill the week.']);
        }

        $weekStart = $weekStart->startOfWeek();
        $days = collect(range(0, 6))->map(fn (int $offset) => $weekStart->addDays($offset))->filter(fn (CarbonImmutable $day) => $day->gte(today()));

        return DB::transaction(function () use ($guards, $weekStart, $days, $mode, $actor) {
            $created = 0;

            foreach ($guards as $guard) {
                $guard->loadMissing('employeeProfile.client');
                RosterRules::authorize($guard, null, $actor);

                $taken = DutyAssignment::query()->where('user_id', $guard->id)
                    ->whereDate('duty_date', '>=', $weekStart->toDateString())->whereDate('duty_date', '<=', $weekStart->addDays(6)->toDateString())
                    ->pluck('duty_date')->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())->flip();

                $lastWeek = $mode === self::COPY
                    ? DutyAssignment::query()->where('user_id', $guard->id)->whereNull('absence_notice_id')
                        ->whereDate('duty_date', '>=', $weekStart->subWeek()->toDateString())->whereDate('duty_date', '<', $weekStart->toDateString())
                        ->get()->keyBy(fn (DutyAssignment $duty) => $duty->duty_date->addWeek()->toDateString())
                    : collect();

                $profile = $guard->employeeProfile;
                $mine = 0;

                foreach ($days as $day) {
                    if ($taken->has($day->toDateString())) {
                        continue;
                    }

                    $source = $mode === self::COPY
                        ? $lastWeek->get($day->toDateString())
                        : ($day->isSunday() || $profile?->shift_id === null ? null : ['shift_id' => $profile->shift_id, 'client_id' => $profile->client_id, 'post' => $profile->post, 'patrols' => str_contains(strtolower((string) $profile->post), 'roving')]);

                    if ($source === null) {
                        continue;
                    }

                    $duty = new DutyAssignment;
                    $duty->forceFill([
                        'user_id' => $guard->id,
                        'duty_date' => $day->toDateString(),
                        'shift_id' => $source instanceof DutyAssignment ? $source->shift_id : $source['shift_id'],
                        'client_id' => $source instanceof DutyAssignment ? $source->client_id : $source['client_id'],
                        'post' => $source instanceof DutyAssignment ? $source->post : $source['post'],
                        'patrols' => $source instanceof DutyAssignment ? $source->patrols : $source['patrols'],
                        'assigned_by' => $actor->id,
                    ])->save();
                    $created++;
                    $mine++;
                }

                // One bell per guard for the whole week, not one per day.
                if ($mine > 0) {
                    UpdateNotice::tell($guard, $actor, 'Your duties for the week of '.$weekStart->format('M j'),
                        "{$mine} ".str('duty')->plural($mine).' set on the roster. Open My Attendance for the days and times.', route('attendance'), 'calendar');
                }
            }

            if ($created > 0) {
                $this->audit->record(AuditArea::Operations, 'duty.filled', "Filled the roster for the week of {$weekStart->format('M j')} ({$created} duties, ".($mode === self::COPY ? 'copied from last week' : 'usual shifts').')', null, causer: $actor);
            }

            return $created;
        });
    }
}
