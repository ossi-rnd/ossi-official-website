<?php

namespace App\Actions\Content;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\EmploymentStatus;
use App\Models\CompanyPolicy;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Adds or edits a section of the Company Policy page. An attached PDF (the
 * signed copy) replaces the previous one; $removeFile drops it.
 */
final class SavePolicy
{
    public function __construct(
        private readonly PrivateUploadStore $uploads,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{title: string, body: string, sort_order: int}  $data
     */
    public function handle(?CompanyPolicy $policy, array $data, ?UploadedFile $file, bool $removeFile, User $actor): CompanyPolicy
    {
        if (! $actor->can(Ability::ManagePolicies->value)) {
            throw new AuthorizationException;
        }

        $disk = (string) config('documents.disk');
        $stored = $file !== null ? $this->uploads->store($file, $disk, 'policies', 'policy', ['pdf'], 'file') : null;
        $oldPath = $policy?->file_path;

        try {
            $policy = DB::transaction(function () use ($policy, $data, $stored, $removeFile, $actor) {
                $isNew = $policy === null;
                $policy ??= new CompanyPolicy;
                $before = $isNew ? [] : $policy->only(['title', 'sort_order']);

                $policy->fill($data);
                $policy->updated_by = $actor->id;

                if ($stored !== null) {
                    $policy->forceFill(['file_path' => $stored['path'], 'file_name' => $stored['name']]);
                } elseif ($removeFile) {
                    $policy->forceFill(['file_path' => null, 'file_name' => null]);
                }

                $bodyChanged = $isNew || $policy->isDirty(['title', 'body', 'file_path']);
                $policy->save();

                // Everyone is bound by company policy, so everyone hears of a new or changed one.
                if ($bodyChanged) {
                    Notification::send(User::query()->whereKeyNot($actor->id)->whereDoesntHave('employeeProfile', fn ($profile) => $profile->whereNotIn('employment_status', EmploymentStatus::active()))->get(),
                        new UpdateNotice(($isNew ? 'New company policy: ' : 'Company policy updated: ').$policy->title, 'Read it on Company Policy.', route('company-policy'), 'book-open'));
                }

                $this->audit->record(AuditArea::Content, $isNew ? 'policy.created' : 'policy.updated', "Saved company policy \"{$policy->title}\"", $policy,
                    AuditLogger::diff($before, $policy->only(['title', 'sort_order'])), causer: $actor);

                return $policy;
            });
        } catch (Throwable $exception) {
            $this->uploads->delete($disk, $stored['path'] ?? null);

            throw $exception;
        }

        // The old file goes only once the row no longer points at it.
        if ($oldPath !== null && $oldPath !== $policy->file_path) {
            $this->uploads->delete($disk, $oldPath);
        }

        return $policy;
    }
}
