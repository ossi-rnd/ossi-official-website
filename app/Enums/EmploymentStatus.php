<?php

namespace App\Enums;

/**
 * Where someone is in their employment. Listed in the order people move
 * through it: a trainee (in pre-deployment or on-the-job training) becomes
 * probationary, then regular; or project-based; until they leave.
 */
enum EmploymentStatus: string
{
    case Trainee = 'trainee';
    case Probationary = 'probationary';
    case Regular = 'regular';
    case ProjectBased = 'project_based';
    case Resigned = 'resigned';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::ProjectBased => 'Project-based',
            default => ucfirst($this->value),
        };
    }

    /**
     * Active employees can register, sign in and appear on payroll.
     */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Resigned, self::Terminated], true);
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->isActive()));
    }
}
