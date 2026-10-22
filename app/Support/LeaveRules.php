<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The leave rules HR sets in the app (Leave Requests → Rules, SaveLeaveRules):
 * from how many days sick leave needs a medical certificate, and how many days
 * after it started sick or emergency leave can still be filed. Company policy,
 * not law. Until HR changes them, the starting values in config/leave.php apply.
 */
final class LeaveRules
{
    public const SETTING = 'leave';

    /**
     * @return array{sick_certificate_from_days: int, file_after_days: int}
     */
    public static function all(): array
    {
        $saved = Setting::value(self::SETTING);
        $pick = fn (string $key) => (int) ($saved[$key] ?? config('leave.'.$key));

        return ['sick_certificate_from_days' => $pick('sick_certificate_from_days'), 'file_after_days' => $pick('file_after_days')];
    }

    /** Sick leave of this many days or more needs a medical certificate before it's approved. */
    public static function sickCertificateFromDays(): int
    {
        return self::all()['sick_certificate_from_days'];
    }

    /** Sick and emergency leave can be filed up to this many days after it started. */
    public static function fileAfterDays(): int
    {
        return self::all()['file_after_days'];
    }
}
