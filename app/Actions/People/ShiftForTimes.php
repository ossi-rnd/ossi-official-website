<?php

namespace App\Actions\People;

use App\Actions\Settings\SaveShift;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * HR typed an employee's times instead of picking a shift ("Custom time").
 * Lateness, undertime and payroll all work from a Shift, so this returns
 * the shift with exactly those times and grace: an existing one if there
 * is one (no duplicates), else a new shift named by its hours, saved through
 * SaveShift so it's audited and shows on the Shifts page like any other.
 */
final class ShiftForTimes
{
    public function __construct(private readonly SaveShift $saveShift) {}

    /**
     * @param  string  $start  HH:MM, 24-hour
     * @param  string  $end  HH:MM, 24-hour (earlier than $start = ends the next day)
     */
    public function handle(string $start, string $end, int $graceMinutes, User $actor): Shift
    {
        // Saved as "07:00" or "07:00:00" depending on how the shift was made.
        $existing = Shift::query()
            ->whereIn('start_time', [$start, $start.':00'])
            ->whereIn('end_time', [$end, $end.':00'])
            ->where('grace_minutes', $graceMinutes)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        return $existing ?? $this->saveShift->handle(null, [
            'name' => $this->uniqueName($start, $end, $graceMinutes),
            'start_time' => $start,
            'end_time' => $end,
            'grace_minutes' => $graceMinutes,
            'is_default' => false,
        ], $actor);
    }

    /**
     * Shift names are unique. Every new one used to be "Custom", so the second set of typed
     * hours crashed (pilot, 2026-10-24). Named by its hours instead: "9:00 AM – 6:00 PM",
     * then with its grace if those hours exist with another grace, then numbered.
     */
    private function uniqueName(string $start, string $end, int $graceMinutes): string
    {
        $hours = Carbon::parse($start)->format('g:i A').' – '.Carbon::parse($end)->format('g:i A');
        $name = $hours;
        if (Shift::query()->where('name', $name)->exists()) {
            $name = "{$hours} · {$graceMinutes} min grace";
        }
        for ($n = 2; Shift::query()->where('name', $name)->exists(); $n++) {
            $name = "{$hours} ({$n})";
        }

        return $name;
    }
}
