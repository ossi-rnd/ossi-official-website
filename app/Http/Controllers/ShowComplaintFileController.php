<?php

namespace App\Http\Controllers;

use App\Enums\AuditArea;
use App\Models\ComplaintFile;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proof attached to a complaint. Never a public URL: the complainant, and the
 * complaint's handler (HR, or the President or Vice President for HR staff's). The handler's views
 * are audited, as for leave documents and 201 files.
 */
final class ShowComplaintFileController extends Controller
{
    public function __invoke(ComplaintFile $file, AuditLogger $audit): StreamedResponse
    {
        $user = request()->user();
        $complaint = $file->complaint;
        abort_unless($user !== null && $complaint->filesVisibleTo($user), 403);

        $disk = Storage::disk((string) config('complaints.files_disk'));
        abort_unless($disk->exists($file->path), 404);

        if ($user->id !== $complaint->user_id) {
            $audit->record(AuditArea::Complaints, 'complaint.proof_viewed', "{$user->name} viewed proof on complaint {$complaint->reference()}", $complaint, causer: $user);
        }

        return $disk->response($file->path, $file->name ?? 'proof', [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
