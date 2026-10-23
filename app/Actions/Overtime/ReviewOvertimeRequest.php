<?php

namespace App\Actions\Overtime;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\RequestStatus;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\RequestDecided;
use App\Services\AuditLogger;
use App\Support\Heads;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Approving sets approved_minutes, which may be LESS than requested. Payroll
 * pays the smaller of logged and approved overtime, day by day.
 *
 * Who decides depends on who's asking, like leave: an office staffer's head (Heads: HR for
 * Admin and others; Operation HQ for a Satellite; the President or VP for HR, Operation HQ, Accounting);
 * Operations, HR or Admin decide guards'.
 */
final class ReviewOvertimeRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Why this reviewer can't decide it, or null when they can: their own request, not
     * their head, not their kind of request, or a guard outside a Satellite's region.
     */
    public static function blockedReason(OvertimeRequest $overtimeRequest, User $reviewer): ?string
    {
        $overtimeRequest->loadMissing('user.employeeProfile');
        $office = $overtimeRequest->user->isOfficeStaff();

        return match (true) {
            $overtimeRequest->user_id === $reviewer->id => 'You cannot review your own request.',
            $office && ! Heads::isHeadOf($reviewer, $overtimeRequest->user) => 'Their head decides it: '.Heads::label($overtimeRequest->user).'.',
            ! $office && ! $reviewer->can(Ability::ReviewOvertime->value) => 'Guards\' overtime is approved by Operations, HR or Admin.',
            ! $reviewer->canSeeEmployee($overtimeRequest->user) => 'This employee is outside your region.',
            default => null,
        };
    }

    public function handle(OvertimeRequest $overtimeRequest, User $reviewer, RequestStatus $decision, ?string $note, ?int $approvedMinutes = null): OvertimeRequest
    {
        if (! in_array($decision, [RequestStatus::Approved, RequestStatus::Rejected], true)) {
            throw new InvalidArgumentException('A review either approves or rejects.');
        }

        if (($reason = self::blockedReason($overtimeRequest, $reviewer)) !== null) {
            throw ValidationException::withMessages(['review' => $reason]);
        }

        return DB::transaction(function () use ($overtimeRequest, $reviewer, $decision, $note, $approvedMinutes) {
            $locked = OvertimeRequest::query()->with('user')->lockForUpdate()->findOrFail($overtimeRequest->id);

            if ($locked->status !== RequestStatus::Pending) {
                throw ValidationException::withMessages(['review' => 'This overtime request has already been reviewed.']);
            }

            $approved = null;

            if ($decision === RequestStatus::Approved) {
                $approved = $approvedMinutes ?? $locked->requested_minutes;

                if ($approved < 1 || $approved > $locked->requested_minutes) {
                    throw ValidationException::withMessages(['approvedHours' => 'Approved time must be more than zero and no more than what was requested.']);
                }
            }

            $locked->forceFill([
                'status' => $decision,
                'approved_minutes' => $approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->audit->record(AuditArea::Overtime, 'overtime.'.$decision->value, ucfirst($decision->value)." overtime of {$locked->user->name} on {$locked->date->format('M j, Y')}", $locked, [
                'old' => ['status' => RequestStatus::Pending->value, 'requested_minutes' => $locked->requested_minutes],
                'new' => ['status' => $decision->value, 'approved_minutes' => $approved, 'review_note' => $note],
            ], causer: $reviewer);

            // The person who asked hears the answer (and how much, if less was approved).
            $locked->user->notify(new RequestDecided($locked->setRelation('reviewer', $reviewer)));

            return $locked;
        });
    }
}
