<?php

namespace App\Actions\Overtime;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\RequestStatus;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\RequestDecided;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Approving sets approved_minutes, which may be LESS than requested. Payroll
 * pays the smaller of logged and approved overtime, day by day.
 *
 * Who decides depends on who's asking, like leave: the President or Vice President decides
 * office staff's overtime; Operations, HR or Admin decide guards'.
 */
final class ReviewOvertimeRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function requiredAbility(OvertimeRequest $overtimeRequest): Ability
    {
        $overtimeRequest->loadMissing('user.employeeProfile');

        return OvertimeRequest::reviewAbilityFor($overtimeRequest->user);
    }

    /**
     * Why this reviewer can't decide it, or null when they can: their own
     * request, not their kind of request, or a guard outside a Satellite
     * Operation's region.
     */
    public static function blockedReason(OvertimeRequest $overtimeRequest, User $reviewer): ?string
    {
        $overtimeRequest->loadMissing('user');

        return match (true) {
            $overtimeRequest->user_id === $reviewer->id => 'You cannot review your own request.',
            ! $reviewer->can(self::requiredAbility($overtimeRequest)->value) => self::requiredAbility($overtimeRequest) === Ability::ReviewOfficeOvertime
                ? 'Office staff overtime is approved by the President or Vice President.'
                : 'Guards\' overtime is approved by Operations, HR or Admin.',
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
