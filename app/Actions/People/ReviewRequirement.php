<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Enums\Requirement;
use App\Enums\RequirementStatus;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRequirement;
use App\Models\User;
use App\Notifications\RequirementNotice;
use App\Services\AuditLogger;
use App\Services\People\RequirementDocumentStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR's decision on one 201 document:
 *
 *   FILE   → on file and verified. Accepts an upload, records a paper copy
 *            HR already holds, or corrects the expiry date.
 *   RETURN → back to missing with a reason; the upload is deleted and the
 *            employee is told why (bell + email).
 */
final class ReviewRequirement
{
    public const FILE = 'file';

    public const RETURN = 'return';

    public function __construct(
        private readonly RequirementDocumentStore $documents,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(EmployeeProfile $profile, Requirement $requirement, string $decision, ?string $expiresOn, ?string $note, User $actor): EmployeeRequirement
    {
        if (! $actor->can('reviewRequirements', $profile)) {
            throw new AuthorizationException;
        }

        if ($decision === self::RETURN && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say why the document is being returned.']);
        }

        $removedPath = null;

        $record = DB::transaction(function () use ($profile, $requirement, $decision, $expiresOn, $note, $actor, &$removedPath) {
            $record = EmployeeRequirement::query()
                ->where('employee_profile_id', $profile->id)
                ->where('requirement', $requirement)
                ->lockForUpdate()
                ->first() ?? new EmployeeRequirement;

            $before = $record->exists ? ['status' => $record->status->value, 'expires_on' => $record->expires_on?->toDateString()] : [];

            if ($decision === self::RETURN) {
                if (! $record->exists || $record->status === RequirementStatus::Missing) {
                    throw ValidationException::withMessages(['note' => 'There is nothing to return.']);
                }

                $removedPath = $record->file_path;
                $record->forceFill([
                    'status' => RequirementStatus::Missing,
                    'file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => null,
                    'expires_on' => null,
                    'note' => $note,
                    'verified_by' => null, 'verified_at' => null,
                ]);
            } else {
                $record->forceFill([
                    'status' => RequirementStatus::OnFile,
                    'expires_on' => $requirement->expires() && filled($expiresOn) ? $expiresOn : null,
                    'promised_on' => null,
                    'note' => filled($note) ? $note : null,
                    'verified_by' => $actor->id,
                    'verified_at' => CarbonImmutable::now(),
                ]);
            }

            $record->forceFill(['employee_profile_id' => $profile->id, 'requirement' => $requirement])->save();

            $this->audit->record(AuditArea::People, $decision === self::RETURN ? 'requirement.returned' : 'requirement.verified', ($decision === self::RETURN ? 'Returned' : 'Verified')." {$requirement->label()} for {$profile->name}", $record, [
                ...AuditLogger::diff($before, ['status' => $record->status->value, 'expires_on' => $record->expires_on?->toDateString()]),
                'note' => $record->note,
            ], causer: $actor);

            $owner = $profile->loadMissing('user')->user;

            if ($decision === self::RETURN && $owner !== null) {
                $owner->notify(new RequirementNotice($record, RequirementNotice::RETURNED, email: true));
            }

            return $record;
        });

        $this->documents->delete($removedPath);

        return $record;
    }
}
