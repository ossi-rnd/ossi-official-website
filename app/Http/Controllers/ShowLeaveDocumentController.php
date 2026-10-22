<?php

namespace App\Http\Controllers;

use App\Enums\AuditArea;
use App\Models\LeaveDocument;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A leave document (medical certificate, birth certificate, protection order).
 * Never a public URL: the employee, and whoever decides the leave
 * (LeaveRequest::documentsVisibleTo). Others' views are audited, as for 201 files.
 */
final class ShowLeaveDocumentController extends Controller
{
    public function __invoke(LeaveDocument $document, AuditLogger $audit): StreamedResponse
    {
        $user = request()->user();
        $leave = $document->leaveRequest;
        abort_unless($user !== null && $leave->documentsVisibleTo($user), 403);

        $disk = Storage::disk((string) config('leave.documents_disk'));
        abort_unless($disk->exists($document->path), 404);

        if ($user->id !== $leave->user_id) {
            $audit->record(AuditArea::Leave, 'leave.document_viewed', "{$user->name} viewed a document of {$leave->user->name}'s leave ({$leave->dateRangeLabel()})", $leave, causer: $user);
        }

        return $disk->response($document->path, $document->name ?? 'document', [
            'Content-Type' => $document->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
