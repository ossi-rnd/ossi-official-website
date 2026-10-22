<?php

namespace App\Support;

use App\Models\EmployeeProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * OSSI employee numbers, the same as the control number on the OSSI ID card
 * (OSSI_ID_GEN_R_SOFTWARE): YYMMDD of the hire date, the 3-letter code of the
 * province of birth (Provinces), a dash, 4 digits, and "-RH" for a rehire.
 * E.g. 260627PAM-0950, 260627PAM-0950-RH.
 *
 * The app generates it (generate()): the 4 digits are random, unique among
 * numbers with the same date and province, like the card app. HR can instead
 * enter the number of an ID card already printed; it must then fit the format.
 *
 * Numbers saved before this format (EMP-1001, 240601-SEC-1001) stay valid where
 * they are; the format applies when a number is created or changed.
 */
final class EmployeeNumber
{
    public const PATTERN = '/^(\d{6})([A-Z]{3})-(\d{4})(-RH)?$/';

    public const EXAMPLE = '260627PAM-0950';

    public const HELP = 'Use the number on the OSSI ID card, e.g. 260627PAM-0950: the hire date (YYMMDD), the province of birth code (PAM for Pampanga, NCR for Metro Manila), a dash, 4 digits, and -RH for a rehire.';

    /**
     * Uppercase, no spaces: "260627pam-0950 " → "260627PAM-0950". A dash left out of a
     * current-format number is put back ("260627PAM0950RH" → "260627PAM-0950-RH");
     * older numbers (EMP-1001) are only uppercased.
     */
    public static function normalize(string $number): string
    {
        $number = strtoupper((string) preg_replace('/\s+/', '', $number));

        return preg_match('/^(\d{6}[A-Z]{3})-?(\d{4})(?:-?(RH))?$/', $number, $match) === 1
            ? $match[1].'-'.$match[2].(($match[3] ?? '') !== '' ? '-RH' : '')
            : $number;
    }

    public static function isValid(string $number): bool
    {
        return self::hireDate($number) !== null && self::provinceCode($number) !== null;
    }

    public static function isRehire(string $number): bool
    {
        return str_ends_with($number, '-RH');
    }

    /** The province-of-birth code in the number ("PAM"), or null if it has none. */
    public static function provinceCode(string $number): ?string
    {
        // Also reads the earlier 260627-PAM-0950 shape, for records saved with it.
        $letters = preg_match('/^\d{6}-?([A-Z]{3})-\d{4}/', $number, $match) === 1 ? $match[1] : '';

        return Provinces::isCode($letters) ? $letters : null;
    }

    /** The hire date in the number (YYMMDD, 20YY), or null if it isn't a real date. */
    public static function hireDate(string $number): ?CarbonImmutable
    {
        if (preg_match(self::PATTERN, $number, $match) !== 1) {
            return null;
        }

        [$year, $month, $day] = [2000 + (int) substr($match[1], 0, 2), (int) substr($match[1], 2, 2), (int) substr($match[1], 4, 2)];

        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day) : null;
    }

    /** "260627PAM": what every number for this hire date and province starts with. */
    public static function prefix(CarbonInterface $hireDate, string $provinceCode): string
    {
        return $hireDate->format('ymd').$provinceCode;
    }

    /**
     * A new number: the prefix, then 4 random digits no one with the same prefix
     * has (rehire or not, as the card app counts them). Picked from the free ones,
     * so it finds one while any is left.
     */
    public static function generate(CarbonInterface $hireDate, string $provinceCode, bool $rehire): string
    {
        $prefix = self::prefix($hireDate, $provinceCode);
        $taken = EmployeeProfile::query()->where('employee_number', 'like', $prefix.'-%')->pluck('employee_number')
            ->map(fn (string $number) => (int) substr($number, strlen($prefix) + 1, 4))
            ->all();
        $free = array_values(array_diff(range(0, 9999), $taken));

        if ($free === []) {
            throw new RuntimeException("No free employee number left for {$prefix}.");
        }

        $sequence = str_pad((string) $free[random_int(0, count($free) - 1)], 4, '0', STR_PAD_LEFT);

        return $prefix.'-'.$sequence.($rehire ? '-RH' : '');
    }
}
