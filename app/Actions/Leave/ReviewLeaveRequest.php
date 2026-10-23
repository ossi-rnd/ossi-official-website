<?php

namespace App\Actions\Leave;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\RequestDecided;
use App\Services\AuditLogger;
use App\Support\Heads;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Who decides depends on who's asking: HR decides guards' leave (final),
 * once the region's Satellite Operation and Operation HQ have endorsed it
 * (EndorseLeaveRequest); an office staffer's head decides theirs (Heads: HR for Admin,
 * Accounting, Operation HQ…; Operation HQ for a Satellite; the President or VP for HR).
 */
final class ReviewLeaveRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Why this reviewer can't decide it, or null when they can. The list shows the same text
     * in place of the Review button. A guard's: HR, once Operations has endorsed it. An office
     * staffer's: their head (Heads). No HR profile (e.g. a Super Admin's own account) counts
     * as office staff.
     */
    public static function blockedReason(LeaveRequest $leaveRequest, User $reviewer): ?string
    {
        $leaveRequest->loadMissing('user.employeeProfile');
        $office = $leaveRequest->user->isOfficeStaff();

        return match (true) {
            $leaveRequest->user_id === $reviewer->id => 'You cannot review your own request.',
            $office && ! Heads::isHeadOf($reviewer, $leaveRequest->user) => 'Their head decides it: '.Heads::label($leaveRequest->user).'.',
            ! $office && ! $reviewer->can(Ability::ReviewFieldLeave->value) => 'Guards\' leave is approved by HR.',
            // A guard's leave reaches HR only once Operation HQ has endorsed it.
            ! $office && $leaveRequest->stage !== null && $leaveRequest->stage->endorses() => "Waiting for {$leaveRequest->stage->label()} to endorse it.",
            ! $reviewer->canSeeEmployee($leaveRequest->user) => 'This employee is outside your region.',
            default => null,
        };
    }

    /**
     * Why it can't be approved yet (rejecting is still possible): the proof it
     * needs isn't attached. The review box shows the same text.
     */
    public static function approvalBlockedReason(LeaveRequest $leaveRequest): ?string
    {
        $leaveRequest->loadMissing('documents');

        return $leaveRequest->missingDocument()
            ? 'Waiting for the document: '.lcfirst((string) $leaveRequest->documentNeeded()).'. They attach it on My Leave.'
            : null;
    }

    public function handle(LeaveRequest $leaveRequest, User $reviewer, RequestStatus $decision, ?string $note): LeaveRequest
    {
        if (! in_array($decision, [RequestStatus::Approved, RequestStatus::Rejected], true)) {
            throw new InvalidArgumentException('A review either approves or rejects.');
        }

        // Separation of duties (nobody approves their own leave), who decides what, and the region.
        if (($reason = self::blockedReason($leaveRequest, $reviewer)) !== null) {
            throw ValidationException::withMessages(['review' => $reason]);
        }

        return DB::transaction(function () use ($leaveRequest, $reviewer, $decision, $note) {
            // Locked: two supervisors clicking at once can't both decide.
            $locked = LeaveRequest::query()->with('user')->lockForUpdate()->findOrFail($leaveRequest->id);

            if ($locked->status !== RequestStatus::Pending) {
                throw ValidationException::withMessages(['review' => 'This leave request has already been reviewed.']);
            }

            // Re-checked on the locked row: an endorsement may have just moved it (or not yet).
            if (($reason = self::blockedReason($locked, $reviewer)) !== null) {
                throw ValidationException::withMessages(['review' => $reason]);
            }

            // Proof first (a medical certificate, a birth certificate…): no approving without it.
            if ($decision === RequestStatus::Approved && ($reason = self::approvalBlockedReason($locked)) !== null) {
                throw ValidationException::withMessages(['review' => $reason]);
            }

            $locked->forceFill([
                'status' => $decision,
                'stage' => null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->audit->record(AuditArea::Leave, 'leave.'.$decision->value, ucfirst($decision->value)." leave of {$locked->user->name} ({$locked->dateRangeLabel()})", $locked, [
                'old' => ['status' => RequestStatus::Pending->value],
                'new' => ['status' => $decision->value, 'review_note' => $note],
            ], causer: $reviewer);

            // The person who asked hears the answer.
            $locked->user->notify(new RequestDecided($locked->setRelation('reviewer', $reviewer)));

            return $locked;
        });
    }
}
