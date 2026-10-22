<?php

namespace App\Enums;

/**
 * pending → accepted → completed, or withdrawn while pending.
 *
 *   pending    filed, waiting for HR (the employee may withdraw it)
 *   accepted   HR agreed the last day; clearance runs until it's all ticked
 *   completed  past the last day (status Resigned) and cleared
 *   withdrawn  the employee took it back before HR accepted
 */
enum ResignationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Completed = 'completed';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Accepted => 'blue',
            self::Completed, self::Withdrawn => 'gray',
        };
    }

    /** Still running: blocks filing another one. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Accepted;
    }
}
