<?php

namespace App\Support;

/**
 * A phone people can recognise: "Samsung SM-A156E · Chrome", or without a model
 * "Android · Chrome", "iPhone · Safari". Only a description: the phone is told
 * apart by its cookie code (DeviceCookie).
 *
 * The model comes from the browser (User-Agent Client Hints, clock-capture.js):
 * Chrome, Samsung Internet and Edge on Android give the model code; iPhones and
 * Firefox give none. The brand is read from the code's pattern (SM- is Samsung).
 */
final class DeviceLabel
{
    /**
     * Model-code patterns => brand. A model that already starts with its brand
     * ("Infinix X6831", "Redmi Note 12") is shown as it is.
     *
     * @var array<string, string>
     */
    private const BRANDS = [
        '/^(SM|GT)-/i' => 'Samsung',
        '/^CPH\d/i' => 'OPPO',
        '/^RMX\d/i' => 'realme',
        '/^(V|I)\d{4}/' => 'vivo',
        '/^\d{4,}[A-Z]{1,4}\d*[A-Z]*$/' => 'Xiaomi',
        '/^M\d{4}[A-Z]\d+[A-Z]*$/' => 'Xiaomi',
        '/^[A-Z]{3}-[A-Z]{1,3}\d{2}/' => 'Huawei',
        '/^LM-/i' => 'LG',
        '/^XQ-/i' => 'Sony',
        '/^TA-\d/i' => 'Nokia',
    ];

    /** Brands a model may already start with. */
    private const NAMED = ['Infinix', 'TECNO', 'itel', 'Redmi', 'POCO', 'Xiaomi', 'Pixel', 'moto', 'motorola', 'Nokia', 'HUAWEI', 'HONOR', 'OnePlus', 'ASUS', 'TCL', 'realme', 'vivo', 'OPPO', 'Galaxy'];

    public static function fromUserAgent(?string $userAgent, ?string $model = null): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $model = self::cleanModel($model);
        $system = $model !== null ? self::withBrand($model) : match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Other device',
        };

        // Order matters: Edge and Samsung's browser also say "Chrome", Chrome also says "Safari".
        $browser = match (true) {
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Edg') => 'Edge',
            str_contains($userAgent, 'Firefox') || str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'CriOS') || str_contains($userAgent, 'Chrome') => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => null,
        };

        return $browser === null ? $system : "{$system} · {$browser}";
    }

    /** "Samsung SM-A156E"; a model naming its brand stays as it is; an unknown pattern is just the model. */
    private static function withBrand(string $model): string
    {
        foreach (self::NAMED as $brand) {
            if (stripos($model, $brand) === 0) {
                return $model;
            }
        }

        foreach (self::BRANDS as $pattern => $brand) {
            if (preg_match($pattern, $model) === 1) {
                return "{$brand} {$model}";
            }
        }

        return $model;
    }

    /** The browser sends it, so keep it short and plain (it's only shown, never trusted). */
    private static function cleanModel(?string $model): ?string
    {
        $model = trim((string) preg_replace('/[^A-Za-z0-9 ._()+\-]/', '', (string) $model));

        // Chrome's reduced User-Agent says "K" for every model; that isn't one.
        return $model === '' || strtoupper($model) === 'K' ? null : mb_substr($model, 0, 40);
    }
}
