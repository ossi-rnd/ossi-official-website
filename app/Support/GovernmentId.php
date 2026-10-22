<?php

namespace App\Support;

/**
 * The four government numbers on an employee record, as the agencies issue them:
 *   SSS         10 digits   00-0000000-0
 *   PhilHealth  12 digits   00-000000000-0  (the PIN on the PhilHealth ID / MDR)
 *   Pag-IBIG    12 digits   0000-0000-0000  (the MID number)
 *   TIN          9 digits   000-000-000, plus a branch code of 3 or 5 digits if
 *                           the card shows one (000-000-000-000 / -00000)
 * Digits only: spaces and dashes are tidied away and put back in the agency's pattern,
 * anything else is refused. resources/js/government-id.js formats the same way as it's typed.
 */
final class GovernmentId
{
    /** @var array<string, array{label: string, groups: list<int>, lengths: list<int>, example: string}> */
    public const FORMATS = [
        'sss_number' => ['label' => 'SSS No.', 'groups' => [2, 7, 1], 'lengths' => [10], 'example' => '00-0000000-0'],
        'philhealth_number' => ['label' => 'PhilHealth No.', 'groups' => [2, 9, 1], 'lengths' => [12], 'example' => '00-000000000-0'],
        'pagibig_number' => ['label' => 'Pag-IBIG No.', 'groups' => [4, 4, 4], 'lengths' => [12], 'example' => '0000-0000-0000'],
        'tin_number' => ['label' => 'TIN', 'groups' => [3, 3, 3, 5], 'lengths' => [9, 12, 14], 'example' => '000-000-000 or 000-000-000-000'],
    ];

    /** Why a typed number is refused, or null when it fits. Blank is allowed (not known yet). */
    public static function problem(string $field, string $value): ?string
    {
        $format = self::FORMATS[$field];
        $compact = preg_replace('/[\s-]+/', '', $value) ?? '';

        if ($compact === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $compact) !== 1) {
            return "Numbers only, like {$format['example']}.";
        }

        if (! in_array(strlen($compact), $format['lengths'], true)) {
            $digits = implode(', ', array_slice($format['lengths'], 0, -1));
            $digits = $digits === '' ? (string) end($format['lengths']) : $digits.' or '.end($format['lengths']);

            return "{$format['label']} has {$digits} digits ({$format['example']}); this one has ".strlen($compact).'.';
        }

        return null;
    }

    /** The number in the agency's pattern ("3412345678" → "34-1234567-8"); unchanged if it isn't digits. */
    public static function format(string $field, string $value): string
    {
        $compact = preg_replace('/[\s-]+/', '', $value) ?? '';

        if (preg_match('/^\d+$/', $compact) !== 1) {
            return trim($value);
        }

        $parts = [];
        foreach (self::FORMATS[$field]['groups'] as $size) {
            if ($compact === '') {
                break;
            }
            $parts[] = substr($compact, 0, $size);
            $compact = substr($compact, $size);
        }

        return implode('-', $parts);
    }
}
