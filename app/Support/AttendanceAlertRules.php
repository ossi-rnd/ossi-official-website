<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The attendance alert rules HR sets in the app (Attendance Log → Alert rules,
 * SaveAttendanceAlertRules): when a missed clock-out is reminded and escalated, when an
 * office staffer who hasn't clocked in is reminded, and which days office staff work
 * (they have no roster, so this is how a rest day is told from a missed day).
 * Company policy, not law. Until HR changes them, config attendance.alerts applies.
 */
final class AttendanceAlertRules
{
    public const SETTING = 'attendance_alerts';

    /** ISO weekday numbers to names, Monday first. */
    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * @return array{clock_out_reminder_minutes: int, clock_out_escalate_minutes: int, office_clock_in_reminder_minutes: int, office_work_days: list<int>}
     */
    public static function all(): array
    {
        $saved = Setting::value(self::SETTING);
        $start = (array) config('attendance.alerts');
        $pick = fn (string $key) => (int) ($saved[$key] ?? $start[$key] ?? 0);
        $days = (array) ($saved['office_work_days'] ?? $start['office_work_days'] ?? []);

        return [
            'clock_out_reminder_minutes' => $pick('clock_out_reminder_minutes'),
            'clock_out_escalate_minutes' => $pick('clock_out_escalate_minutes'),
            'office_clock_in_reminder_minutes' => $pick('office_clock_in_reminder_minutes'),
            'office_work_days' => array_values(array_map('intval', $days)),
        ];
    }

    /** "Mon–Fri", "Mon–Sat", or a list like "Mon, Wed, Fri". */
    public static function daysLabel(): string
    {
        $days = self::all()['office_work_days'];
        sort($days);

        if ($days === []) {
            return 'None';
        }

        $consecutive = $days === range($days[0], end($days));

        return $consecutive && count($days) > 2
            ? self::DAYS[$days[0]].'–'.self::DAYS[end($days)]
            : implode(', ', array_map(fn (int $day) => self::DAYS[$day], $days));
    }
}
