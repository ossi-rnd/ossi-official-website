<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\CompanyDocument;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company documents are never public URLs: streamed to manage-documents only,
 * and every view is audited.
 */
final class ShowCompanyDocumentController extends Controller
{
    public function __invoke(CompanyDocument $document, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize(Ability::ManageDocuments->value);

        $disk = Storage::disk((string) config('documents.disk'));
        abort_unless($disk->exists($document->file_path), 404);

        $audit->record(AuditArea::Documents, 'document.viewed', "Viewed \"{$document->title}\"", $document);

        return $disk->response($document->file_path, $document->file_name, [
            'Content-Type' => $document->file_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
