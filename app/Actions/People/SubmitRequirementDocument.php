<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\Requirement;
use App\Enums\RequirementStatus;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRequirement;
use App\Models\User;
use App\Notifications\RequirementSubmitted;
use App\Services\AuditLogger;
use App\Services\People\RequirementDocumentStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Uploads one 201 document. From HR it is filed (verified) at once; from the
 * employee it waits for HR's check, and HR gets a bell notification.
 *
 * File and row stay consistent: the new file is written first, deleted
 * again if the transaction fails, and the replaced file is only deleted
 * after the commit.
 */
final class SubmitRequirementDocument
{
    public function __construct(
        private readonly RequirementDocumentStore $documents,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(EmployeeProfile $profile, Requirement $requirement, UploadedFile $file, ?string $expiresOn, User $actor): EmployeeRequirement
    {
        if (! $actor->can('submitRequirements', $profile)) {
            throw new AuthorizationException;
        }

        if (! in_array($requirement, $profile->applicableRequirements(), true)) {
            throw ValidationException::withMessages(['document' => 'This document is not required for this employee.']);
        }

        $byHr = $actor->can(Ability::ManagePeople->value);
        $stored = $this->documents->store($file, $profile, $requirement);
        $replacedPath = null;

        try {
            $record = DB::transaction(function () use ($profile, $requirement, $stored, $expiresOn, $actor, $byHr, &$replacedPath) {
                $record = EmployeeRequirement::query()
                    ->where('employee_profile_id', $profile->id)
                    ->where('requirement', $requirement)
                    ->lockForUpdate()
                    ->first() ?? new EmployeeRequirement;

                $replacedPath = $record->file_path;
                $now = CarbonImmutable::now();

                $record->forceFill([
                    'employee_profile_id' => $profile->id,
                    'requirement' => $requirement,
                    'status' => $byHr ? RequirementStatus::OnFile : RequirementStatus::Submitted,
                    'file_path' => $stored['path'],
                    'file_name' => $stored['name'],
                    'file_mime' => $stored['mime'],
                    'file_size' => $stored['size'],
                    'expires_on' => $requirement->expires() && filled($expiresOn) ? $expiresOn : null,
                    'promised_on' => null,
                    'note' => null,
                    'submitted_by' => $actor->id,
                    'submitted_at' => $now,
                    'verified_by' => $byHr ? $actor->id : null,
                    'verified_at' => $byHr ? $now : null,
                ])->save();

                $this->audit->record(AuditArea::People, $byHr ? 'requirement.filed' : 'requirement.submitted', ($byHr ? 'Filed' : 'Submitted')." {$requirement->label()} for {$profile->name}", $record, [
                    'requirement' => $requirement->value,
                    'file_name' => $stored['name'],
                    'expires_on' => $record->expires_on?->toDateString(),
                    'replaced_file' => $replacedPath !== null,
                ], causer: $actor);

                if (! $byHr) {
                    Notification::send(User::query()->notifiable(Ability::ManagePeople->roles())->get(), new RequirementSubmitted($record));
                }

                return $record;
            });
        } catch (Throwable $exception) {
            $this->documents->delete($stored['path']);

            throw $exception;
        }

        $this->documents->delete($replacedPath);

        return $record;
    }
}
