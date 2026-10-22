<?php

namespace App\Support;

/**
 * Normalizers for what people type, so "+63 917 123 4567", "0917-123-4567"
 * and "639171234567" are all stored as 09171234567.
 */
final class PhilippineFormats
{
    public static function mobileNumber(string $input): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $input);

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^09\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /** A mobile as people read it: "0917 123 4567"; null if it isn't a Philippine mobile. */
    public static function readableMobile(string $input): ?string
    {
        $mobile = self::mobileNumber($input);

        return $mobile === null ? null : substr($mobile, 0, 4).' '.substr($mobile, 4, 3).' '.substr($mobile, 7);
    }

    /**
     * A mobile (made readable) or a landline, kept as typed but tidied: "(02) 8123 4567",
     * "046 123 4567", "8123 4567". A landline is 0 + area code (2-8) + the number, 9-10 digits,
     * or the local number alone (7-8). Anything else, letters included, is null, and so is a
     * mobile with a digit missing (it starts with 09, so it can't pass for a landline).
     */
    public static function phoneNumber(string $input): ?string
    {
        $mobile = self::readableMobile($input);
        if ($mobile !== null) {
            return $mobile;
        }

        $tidy = trim((string) preg_replace('/\s+/', ' ', $input));
        $digits = (string) preg_replace('/\D+/', '', $tidy);
        if (str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        }

        return preg_match('/^[\d\s()+\-]+$/', $tidy) === 1 && preg_match('/^(0[2-8]\d{7,8}|[2-8]\d{6,7})$/', $digits) === 1 ? $tidy : null;
    }

    /** A person's name: letters (ñ, é…), spaces, and . , ' - as in "Ma. Cristina Dela Cruz-Reyes Jr." */
    public const NAME_PATTERN = "/^[\pL\pM][\pL\pM .,'\-]*$/u";

    public const NAME_MESSAGE = 'Letters only; spaces, periods, commas, hyphens and apostrophes are fine.';

    /** A name with its spaces tidied: "  juan   dela cruz " → "juan dela cruz". */
    public static function name(string $input): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $input));
    }
}
