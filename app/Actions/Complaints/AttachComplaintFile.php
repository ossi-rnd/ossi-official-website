<?php

namespace App\Actions\Complaints;

use App\Enums\AuditArea;
use App\Models\Complaint;
use App\Models\ComplaintFile;
use App\Models\User;
use App\Notifications\ComplaintNotice;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The complainant attaches proof (a screenshot, a photo, a document), when filing
 * or later while the case is open. Optional: a complaint never needs it to be
 * filed. Checked by its bytes, kept private. Added later, the handler is told,
 * and a request for proof counts as answered.
 */
final class AttachComplaintFile
{
    public function __construct(
        private readonly PrivateUploadStore $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $employee, Complaint $complaint, UploadedFile $file, bool $notify = true): ComplaintFile
    {
        if ($complaint->user_id !== $employee->id) {
            throw new AuthorizationException;
        }

        if ($complaint->status->isClosed()) {
            throw ValidationException::withMessages(['proof' => 'This complaint is closed.']);
        }

        $max = (int) config('complaints.max_files');

        if ($complaint->files()->count() >= $max) {
            throw ValidationException::withMessages(['proof' => "Up to {$max} files. Put several pages or screenshots in one PDF."]);
        }

        $stored = $this->uploads->store($file, (string) config('complaints.files_disk'), 'complaints/'.$complaint->id, 'proof', ['png', 'jpg', 'pdf'], 'proof');

        $attached = new ComplaintFile;
        $attached->forceFill([
            'complaint_id' => $complaint->id,
            'path' => $stored['path'],
            'name' => $stored['name'],
            'mime' => $stored['mime'],
            'uploaded_by' => $employee->id,
        ])->save();

        // That proof was added, never what it is.
        $this->audit->record(AuditArea::Complaints, 'complaint.proof_added', "Proof added to complaint {$complaint->reference()}", $complaint, causer: $employee);

        if ($notify) {
            $complaint->forceFill(['proof_requested_at' => null])->save();
            $handlers = User::query()->notifiable($complaint->handlerAbility()->roles())->whereKeyNot($employee->id)->get();
            Notification::send($handlers, new ComplaintNotice($complaint->setRelation('user', $employee), forHr: true, event: ComplaintNotice::PROOF_ADDED));
        }

        return $attached;
    }
}
