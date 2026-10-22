<?php

namespace App\Enums;

/**
 * missing   → nothing yet, or HR returned it (the note says why)
 * submitted → the employee uploaded it; HR hasn't checked it
 * on_file   → HR verified it (an upload, or a paper copy in the cabinet)
 *
 * "Expired" is not a status: it is computed from expires_on, so it can
 * never be out of date.
 */
enum RequirementStatus: string
{
    case Missing = 'missing';
    case Submitted = 'submitted';
    case OnFile = 'on_file';

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Missing',
            self::Submitted => 'Awaiting HR check',
            self::OnFile => 'On file',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Missing => 'gray',
            self::Submitted => 'amber',
            self::OnFile => 'emerald',
        };
    }
}
