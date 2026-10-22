<?php

namespace App\Enums;

/**
 * open → investigating → resolved or dismissed. Resolved and dismissed are
 * closed and need HR's written outcome, which the employee sees.
 */
enum ComplaintStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'amber',
            self::Investigating => 'blue',
            self::Resolved => 'gray',
            self::Dismissed => 'gray',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Resolved || $this === self::Dismissed;
    }
}
