<?php

namespace App\Actions\Leave;

use App\Enums\AuditArea;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelLeaveRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(LeaveRequest $leaveRequest, User $actor): void
    {
        abort_unless($leaveRequest->user_id === $actor->id, 403);

        DB::transaction(function () use ($leaveRequest, $actor) {
            $locked = LeaveRequest::query()->lockForUpdate()->findOrFail($leaveRequest->id);

            if ($locked->status !== RequestStatus::Pending) {
                throw ValidationException::withMessages(['leave' => 'Only a pending request can be cancelled.']);
            }

            $locked->status = RequestStatus::Cancelled;
            $locked->save();

            $this->audit->record(AuditArea::Leave, 'leave.cancelled', "Cancelled leave {$locked->dateRangeLabel()}", $locked, causer: $actor);
        });
    }
}
