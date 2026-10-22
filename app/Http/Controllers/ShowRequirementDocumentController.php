<?php

namespace App\Http\Controllers;

use App\Enums\AuditArea;
use App\Models\EmployeeRequirement;
use App\Services\AuditLogger;
use App\Services\People\RequirementDocumentStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 201 scans are never public URLs. Each view passes EmployeeRequirementPolicy
 * (the employee or HR) and is audited: NBI clearances and medical results
 * are the most sensitive files in the system.
 */
final class ShowRequirementDocumentController extends Controller
{
    public function __invoke(EmployeeRequirement $requirement, RequirementDocumentStore $documents, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('viewDocument', $requirement);

        $disk = Storage::disk($documents->disk());
        abort_unless($requirement->file_path !== null && $disk->exists($requirement->file_path), 404);

        $audit->record(AuditArea::People, 'requirement.document_viewed', "Viewed {$requirement->requirement->label()} of {$requirement->profile->name}", $requirement);

        return $disk->response($requirement->file_path, $requirement->file_name, [
            'Content-Type' => (string) $requirement->file_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
