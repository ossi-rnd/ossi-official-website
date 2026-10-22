<?php

namespace App\Actions\Leave;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\LeaveRouting;
use App\Support\LeaveRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * An employee files leave. Sick and emergency leave may have started already
 * (filed once back, up to LeaveRules::fileAfterDays() days, which HR sets); the rest from
 * today on. Proof it needs (LeaveType::document) can come with it or later;
 * the decider can't approve without it. VAWC goes straight to the decider.
 */
final class SubmitLeaveRequest
{
    public function __construct(private readonly AttachLeaveDocument $attach) {}

    /**
     * @param  list<UploadedFile>  $documents
     */
    public function handle(User $user, LeaveType $type, CarbonImmutable $start, CarbonImmutable $end, string $reason, array $documents = []): LeaveRequest
    {
        $today = CarbonImmutable::today();
        $earliest = $type->canStartInThePast() ? $today->subDays(LeaveRules::fileAfterDays()) : $today;

        if ($start->lt($earliest)) {
            throw ValidationException::withMessages(['start_date' => $type->canStartInThePast()
                ? 'Sick or emergency leave can be filed up to '.LeaveRules::fileAfterDays().' days after it started (from '.$earliest->format('M j').').'
                : 'The start date can\'t be in the past.']);
        }

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['end_date' => 'The end date can\'t be before the start date.']);
        }

        $overlaps = $user->leaveRequests()
            ->overlapping($start, $end)
            ->whereIn('status', RequestStatus::blocking())
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['start_date' => 'You already have a pending or approved leave request that overlaps these dates.']);
        }

        $leave = $user->leaveRequests()->create([
            'type' => $type,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'reason' => $reason,
        ]);

        // Office staff's to the President or Vice President; a guard's to their region's Satellite Operation (or Operation HQ).
        $leave->forceFill(['stage' => LeaveRouting::firstStage($user, $type)])->save();

        foreach ($documents as $document) {
            $this->attach->handle($user, $leave, $document, notify: false);
        }

        LeaveRouting::notifyNext($leave->setRelation('user', $user));

        return $leave;
    }
}
