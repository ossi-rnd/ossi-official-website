<?php

namespace App\Actions\Complaints;

use App\Enums\AuditArea;
use App\Models\Complaint;
use App\Models\User;
use App\Notifications\ComplaintNotice;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The handler asks the complainant for proof, saying what would help (a
 * screenshot, a photo, who saw it). Asking, not requiring: a complaint without
 * proof is still investigated (RA 11313, RA 7877). They add it on My Complaints.
 */
final class RequestComplaintProof
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $handler, Complaint $complaint, string $note): Complaint
    {
        if (! Complaint::query()->handledBy($handler)->whereKey($complaint->id)->exists()) {
            throw ValidationException::withMessages(['proofNote' => 'This isn\'t one of your cases.']);
        }

        if ($complaint->status->isClosed()) {
            throw ValidationException::withMessages(['proofNote' => 'This complaint is closed.']);
        }

        if ($complaint->user_id === $handler->id) {
            throw ValidationException::withMessages(['proofNote' => 'You can\'t handle your own complaint.']);
        }

        return DB::transaction(function () use ($handler, $complaint, $note) {
            $complaint->forceFill(['proof_requested_at' => now(), 'proof_request_note' => trim($note)])->save();

            // That proof was asked for, not what.
            $this->audit->record(AuditArea::Complaints, 'complaint.proof_requested', "Proof asked for on complaint {$complaint->reference()}", $complaint, causer: $handler);

            $complaint->loadMissing('user');
            $complaint->user->notify(new ComplaintNotice($complaint, forHr: false, event: ComplaintNotice::PROOF_REQUESTED));

            return $complaint;
        });
    }
}
