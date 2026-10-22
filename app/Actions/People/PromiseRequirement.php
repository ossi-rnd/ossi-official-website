<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Enums\Requirement;
use App\Enums\RequirementStatus;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRequirement;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "I'll submit it by…" for a missing document (V2 item 18). The daily alert
 * check reminds the employee and HR once that date passes.
 */
final class PromiseRequirement
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(EmployeeProfile $profile, Requirement $requirement, string $promisedOn, User $actor): EmployeeRequirement
    {
        if (! $actor->can('submitRequirements', $profile)) {
            throw new AuthorizationException;
        }

        if (CarbonImmutable::parse($promisedOn)->lessThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['promisedOn' => 'Pick today or a later date.']);
        }

        return DB::transaction(function () use ($profile, $requirement, $promisedOn, $actor) {
            $record = EmployeeRequirement::query()
                ->where('employee_profile_id', $profile->id)
                ->where('requirement', $requirement)
                ->lockForUpdate()
                ->first() ?? new EmployeeRequirement;

            if ($record->exists && $record->status !== RequirementStatus::Missing) {
                throw ValidationException::withMessages(['promisedOn' => 'This document has already been submitted.']);
            }

            $record->forceFill([
                'employee_profile_id' => $profile->id,
                'requirement' => $requirement,
                'status' => RequirementStatus::Missing,
                'promised_on' => $promisedOn,
            ])->save();

            $this->audit->record(AuditArea::People, 'requirement.promised', "{$requirement->label()} promised by {$record->promised_on?->format('M j, Y')} for {$profile->name}", $record, [
                'requirement' => $requirement->value,
                'promised_on' => $record->promised_on?->toDateString(),
            ], causer: $actor);

            return $record;
        });
    }
}
