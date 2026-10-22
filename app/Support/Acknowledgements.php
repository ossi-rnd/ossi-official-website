<?php

namespace App\Support;

use App\Enums\Role;

/**
 * The acknowledgement and authorization wording (lang/en and lang/fil
 * acknowledgements.php), in the language the employee chose to read it in.
 */
final class Acknowledgements
{
    /** The languages offered: English, and Filipino for those who read it better. */
    public const LOCALES = ['en' => 'English', 'fil' => 'Filipino'];

    public static function locale(?string $locale): string
    {
        return array_key_exists((string) $locale, self::LOCALES) ? (string) $locale : 'en';
    }

    /**
     * @param  array<string, string>  $replace
     */
    public static function line(string $key, string $locale, array $replace = []): string
    {
        $text = trans('acknowledgements.'.$key, $replace, self::locale($locale));

        return is_string($text) ? $text : $key;
    }

    /**
     * "HR" or "the President or Vice President" in English; "HR" or "Presidente o Bise
     * Presidente" in Filipino (the sentence has its own article).
     */
    public static function handler(string $handler, string $locale): string
    {
        if (self::locale($locale) !== 'fil') {
            return $handler;
        }

        return $handler === Role::LEADERSHIP_LABEL ? 'Presidente o Bise Presidente' : str_replace('the ', '', $handler);
    }
}
