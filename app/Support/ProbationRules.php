<?php

namespace App\Support;

use App\Models\EmployeeProfile;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * The probation and training lengths HR sets in the app (Employees → Probation & training
 * rules, SaveProbationRules). Until HR changes them, the starting values in config/hr.php apply.
 *
 *   months              probation length, 1 to 6 (Labor Code Art. 296: never more than 6)
 *   remind_days_before  when the head and HR are first told to evaluate
 *   training_days       how long a Trainee trains before HR is told to move them on
 *
 * Both periods count from the hire date.
 */
final class ProbationRules
{
    public const SETTING = 'probation';

    public const MAX_MONTHS = 6;

    public static function months(): int
    {
        return self::get('months');
    }

    public static function remindDaysBefore(): int
    {
        return self::get('remind_days_before');
    }

    public static function trainingDays(): int
    {
        return self::get('training_days');
    }

    /** The last day of probation; from the next day they're Regular unless a fail was approved. */
    public static function endsOn(EmployeeProfile $profile): ?CarbonImmutable
    {
        return $profile->hire_date === null ? null : CarbonImmutable::parse($profile->hire_date)->addMonthsNoOverflow(self::months());
    }

    /** The day a Trainee's training is due to end. */
    public static function trainingEndsOn(EmployeeProfile $profile): ?CarbonImmutable
    {
        return $profile->hire_date === null ? null : CarbonImmutable::parse($profile->hire_date)->addDays(self::trainingDays());
    }

    private static function get(string $key): int
    {
        $saved = Setting::value(self::SETTING);

        return (int) ($saved[$key] ?? config("hr.probation.{$key}"));
    }
}
