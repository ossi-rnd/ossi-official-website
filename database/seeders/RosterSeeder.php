<?php

namespace Database\Seeders;

use App\Models\DutyAssignment;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A two-week demo roster from today: every active guard, one rest day a
 * week (staggered, so posts are never empty on the same day), on 9-hour
 * duties. Night-shift guards get Night Duty; the rest alternate Day and Mid
 * Duty. Maria keeps her usual 09:00–18:00 and works today, so the demo
 * clock-in always works.
 *
 * Safe on a database that already has data (`php artisan db:seed
 * --class=RosterSeeder`): guards who already have duties are skipped.
 */
class RosterSeeder extends Seeder
{
    public function run(): void
    {
        $templates = Shift::query()->whereIn('name', ['Day Duty', 'Mid Duty', 'Night Duty'])->get()->keyBy('name');
        [$dayDuty, $midDuty, $nightDuty] = [$templates->get('Day Duty'), $templates->get('Mid Duty'), $templates->get('Night Duty')];

        if ($dayDuty === null || $midDuty === null || $nightDuty === null) {
            $this->command->warn('Run ShiftSeeder first: the duty templates are missing.');

            return;
        }

        $assigner = User::query()->whereHas('roles', fn ($query) => $query->where('role', 'operations-hq'))->first();
        $today = CarbonImmutable::today();

        $guards = User::query()->onPayroll()
            ->whereHas('employeeProfile', fn ($query) => $query->where('is_office_staff', false))
            ->whereDoesntHave('dutyAssignments')
            ->with('employeeProfile.shift')
            ->orderBy('id')
            ->get()
            ->values();

        foreach ($guards as $index => $guard) {
            $profile = $guard->employeeProfile;
            $isMaria = $guard->email === 'employee@example.test';

            $shift = match (true) {
                $isMaria && $profile?->shift !== null => $profile->shift,
                (bool) $profile?->shift?->crossesMidnight() => $nightDuty,
                $index % 2 === 0 => $dayDuty,
                default => $midDuty,
            };

            // Rest day: a different weekday per guard, and never today for Maria.
            $restDay = $isMaria ? $today->addDays(3)->dayOfWeek : $index % 7;

            for ($offset = 0; $offset < 14; $offset++) {
                $day = $today->addDays($offset);

                if ($day->dayOfWeek === $restDay) {
                    continue;
                }

                $duty = new DutyAssignment;
                $duty->forceFill([
                    'user_id' => $guard->id,
                    'duty_date' => $day->toDateString(),
                    'shift_id' => $shift->id,
                    'client_id' => $profile?->client_id,
                    'post' => $profile?->post,
                    // Roving guards patrol the site's checkpoints.
                    'patrols' => str_contains(strtolower((string) $profile?->post), 'roving'),
                    'assigned_by' => $assigner?->id,
                ])->save();
            }
        }
    }
}
