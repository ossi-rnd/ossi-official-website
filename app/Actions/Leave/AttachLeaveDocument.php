<?php

namespace App\Actions\Leave;

use App\Enums\AuditArea;
use App\Enums\RequestStatus;
use App\Models\LeaveDocument;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use App\Support\LeaveRouting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The employee attaches proof to their leave (a medical certificate, a birth
 * certificate…), when filing or after, while it's pending. Checked by its
 * bytes, kept private. Attached after filing, whoever acts next is told again.
 */
final class AttachLeaveDocument
{
    public const MAX_FILES = 5;

    public function __construct(
        private readonly PrivateUploadStore $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $employee, LeaveRequest $leave, UploadedFile $file, bool $notify = true): LeaveDocument
    {
        if ($leave->user_id !== $employee->id) {
            throw new AuthorizationException;
        }

        if ($leave->status !== RequestStatus::Pending) {
            throw ValidationException::withMessages(['document' => 'It was already decided.']);
        }

        if ($leave->documents()->count() >= self::MAX_FILES) {
            throw ValidationException::withMessages(['document' => 'Up to '.self::MAX_FILES.' files. Put several pages in one PDF.']);
        }

        $disk = (string) config('leave.documents_disk');
        $stored = $this->uploads->store($file, $disk, 'leave/'.$employee->id, 'leave', ['png', 'jpg', 'pdf'], 'document');

        $document = new LeaveDocument;
        $document->forceFill([
            'leave_request_id' => $leave->id,
            'path' => $stored['path'],
            'name' => $stored['name'],
            'mime' => $stored['mime'],
            'uploaded_by' => $employee->id,
        ])->save();

        // That a document was added, never what it is (it may be medical or VAWC).
        $this->audit->record(AuditArea::Leave, 'leave.document_added', "{$employee->name} attached a document to their leave ({$leave->dateRangeLabel()})", $leave, causer: $employee);

        if ($notify) {
            LeaveRouting::notifyNext($leave->setRelation('user', $employee));
        }

        return $document;
    }
}
