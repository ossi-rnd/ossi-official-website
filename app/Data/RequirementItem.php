<?php

namespace App\Data;

use App\Enums\Requirement;
use App\Enums\RequirementStatus;
use App\Models\EmployeeRequirement;

/**
 * One line of a 201 checklist: the requirement, its row if any, and the
 * states the screen needs, computed once so Blade only displays them.
 */
final readonly class RequirementItem
{
    public function __construct(
        public Requirement $requirement,
        public ?EmployeeRequirement $record,
        public RequirementStatus $status,
        public bool $isExpired,
        public ?int $daysToExpiry,
        public bool $isPromiseOverdue,
    ) {}

    public function expiresSoon(): bool
    {
        return ! $this->isExpired && $this->daysToExpiry !== null && $this->daysToExpiry <= 30;
    }

    public function needsAction(): bool
    {
        return $this->status !== RequirementStatus::OnFile || $this->isExpired;
    }
}
