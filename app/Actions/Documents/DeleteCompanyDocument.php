<?php

namespace App\Actions\Documents;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\CompanyDocument;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a document and its earlier copies (the renewals chain behind it),
 * for an upload made by mistake. Files go after the rows are gone.
 */
final class DeleteCompanyDocument
{
    public function __construct(
        private readonly PrivateUploadStore $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CompanyDocument $document, User $actor): void
    {
        if (! $actor->can(Ability::ManageDocuments->value)) {
            throw new AuthorizationException;
        }

        $chain = [$document];

        while (($older = CompanyDocument::query()->where('replaced_by_id', end($chain)->id)->first()) !== null) {
            $chain[] = $older;
        }

        DB::transaction(function () use ($document, $chain, $actor) {
            $this->audit->record(AuditArea::Documents, 'document.deleted', "Deleted {$document->category->singular()} \"{$document->title}\"", $document, [
                'old' => ['title' => $document->title, 'copies' => count($chain)],
            ], causer: $actor);

            // Oldest first, so no row is left pointing at a deleted one.
            foreach (array_reverse($chain) as $copy) {
                $copy->delete();
            }
        });

        foreach ($chain as $copy) {
            $this->uploads->delete((string) config('documents.disk'), $copy->file_path);
        }
    }
}
