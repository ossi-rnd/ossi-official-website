<?php

namespace App\Actions\Attendance;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AttendanceAlertRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * HR sets the attendance alert rules in the app: minutes after a shift before a missed
 * clock-out is reminded, then escalated to the head; minutes after an office shift starts
 * before a missing clock-in is reminded; and office staff's work days. Audited.
 */
final class SaveAttendanceAlertRules
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<int>  $officeWorkDays  ISO weekdays, 1 = Monday
     */
    public function handle(User $actor, int $clockOutReminder, int $clockOutEscalate, int $officeClockInReminder, array $officeWorkDays): void
    {
        if (! $actor->can(Ability::ManagePeople->value)) {
            throw new AuthorizationException;
        }

        $officeWorkDays = array_values(array_unique(array_filter(array_map('intval', $officeWorkDays), fn (int $day) => $day >= 1 && $day <= 7)));
        sort($officeWorkDays);

        $errors = array_filter([
            'clockOutReminder' => $clockOutReminder < 5 || $clockOutReminder > 240 ? 'From 5 to 240 minutes.' : null,
            'clockOutEscalate' => match (true) {
                $clockOutEscalate < 15 || $clockOutEscalate > 1440 => 'From 15 to 1,440 minutes.',
                $clockOutEscalate <= $clockOutReminder => 'Later than the reminder to the employee.',
                default => null,
            },
            'officeClockInReminder' => $officeClockInReminder < 5 || $officeClockInReminder > 240 ? 'From 5 to 240 minutes.' : null,
            'officeWorkDays' => $officeWorkDays === [] ? 'Pick at least one day.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $old = AttendanceAlertRules::all();
        $new = [
            'clock_out_reminder_minutes' => $clockOutReminder,
            'clock_out_escalate_minutes' => $clockOutEscalate,
            'office_clock_in_reminder_minutes' => $officeClockInReminder,
            'office_work_days' => $officeWorkDays,
        ];

        if ($old === $new) {
            return;
        }

        $setting = Setting::query()->find(AttendanceAlertRules::SETTING) ?? new Setting;
        $setting->forceFill(['key' => AttendanceAlertRules::SETTING, 'value' => $new, 'updated_by' => $actor->id])->save();

        $this->audit->record(AuditArea::Attendance, 'attendance.alert_rules_changed', "Attendance alerts: clock-out reminder after {$clockOutReminder} min, to the head after {$clockOutEscalate} min; office clock-in reminder after {$officeClockInReminder} min; office days ".AttendanceAlertRules::daysLabel(), $setting, ['old' => $old, 'new' => $new], causer: $actor);
    }
}
