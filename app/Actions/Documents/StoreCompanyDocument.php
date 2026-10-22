<?php

namespace App\Actions\Documents;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\DocumentCategory;
use App\Models\Client;
use App\Models\CompanyDocument;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Uploads a document, or a renewal of one ($renewing): the renewal is a new
 * row and the old copy points at it, so nothing is overwritten and each
 * license keeps its history.
 */
final class StoreCompanyDocument
{
    public function __construct(
        private readonly PrivateUploadStore $uploads,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{category: DocumentCategory, title: string, client: Client|null, reference_number: string|null, issued_on: CarbonImmutable|null, expires_on: CarbonImmutable|null, notes: string|null}  $data
     */
    public function handle(array $data, UploadedFile $file, User $actor, ?CompanyDocument $renewing = null): CompanyDocument
    {
        if (! $actor->can(Ability::ManageDocuments->value)) {
            throw new AuthorizationException;
        }

        if ($data['issued_on'] !== null && $data['expires_on'] !== null && $data['expires_on']->lt($data['issued_on'])) {
            throw ValidationException::withMessages(['expiresOn' => 'The due date can\'t be before the issue date.']);
        }

        if ($renewing !== null && $renewing->replaced_by_id !== null) {
            throw ValidationException::withMessages(['file' => 'This copy was already renewed. Renew the current one.']);
        }

        $disk = (string) config('documents.disk');
        $stored = $this->uploads->store($file, $disk, 'company-documents', $data['category']->value, ['pdf', 'png', 'jpg'], 'file');

        try {
            return DB::transaction(function () use ($data, $stored, $actor, $renewing) {
                $document = new CompanyDocument;
                $document->forceFill([
                    'category' => $data['category'],
                    'title' => trim($data['title']),
                    'client_id' => $data['client']?->id,
                    'reference_number' => $data['reference_number'],
                    'issued_on' => $data['issued_on']?->toDateString(),
                    'expires_on' => $data['expires_on']?->toDateString(),
                    'notes' => $data['notes'],
                    'file_path' => $stored['path'],
                    'file_name' => $stored['name'],
                    'file_mime' => $stored['mime'],
                    'file_size' => $stored['size'],
                    'uploaded_by' => $actor->id,
                ])->save();

                if ($renewing !== null) {
                    CompanyDocument::query()->whereKey($renewing->id)->whereNull('replaced_by_id')->update(['replaced_by_id' => $document->id]);
                }

                $this->audit->record(AuditArea::Documents, $renewing !== null ? 'document.renewed' : 'document.uploaded',
                    ($renewing !== null ? 'Renewed ' : 'Uploaded ')."{$data['category']->singular()} \"{$document->title}\"", $document, [
                        'new' => ['expires_on' => $document->expires_on?->toDateString(), 'reference_number' => $document->reference_number],
                    ], causer: $actor);

                return $document;
            });
        } catch (Throwable $exception) {
            // Nothing points at the file any more.
            $this->uploads->delete($disk, $stored['path']);

            throw $exception;
        }
    }
}
