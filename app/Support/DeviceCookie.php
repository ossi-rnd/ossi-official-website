<?php

namespace App\Support;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * The code that tells one phone from another (CheckPunchDevice): random, kept
 * in a long-lived cookie that Laravel encrypts and the page's scripts can't read
 * (HttpOnly). Clearing the browser's data, or another browser, is a new phone.
 */
final class DeviceCookie
{
    public const NAME = 'ossi_device';

    /** About five years: as long as a company phone lasts. */
    private const MINUTES = 60 * 24 * 365 * 5;

    /** This phone's code, given one now if it has none (sent back with the response). */
    public static function token(): string
    {
        $token = request()->cookie(self::NAME);

        if (is_string($token) && preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1) {
            return $token;
        }

        $token = Str::random(40);
        Cookie::queue(Cookie::make(self::NAME, $token, self::MINUTES, httpOnly: true, sameSite: 'lax'));

        return $token;
    }
}
