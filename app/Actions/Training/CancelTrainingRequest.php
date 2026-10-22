<?php

namespace App\Actions\Training;

use App\Enums\AuditArea;
use App\Enums\RequestStatus;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The guard withdraws their own training request while it's still pending. */
final class CancelTrainingRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $guard, int $requestId): TrainingRequest
    {
        return DB::transaction(function () use ($guard, $requestId) {
            // Through the owner: someone else's id is simply not found.
            $request = TrainingRequest::query()->where('user_id', $guard->id)->lockForUpdate()->findOrFail($requestId);

            if ($request->status !== RequestStatus::Pending) {
                throw ValidationException::withMessages(['list' => 'Only a pending request can be cancelled.']);
            }

            $request->forceFill(['status' => RequestStatus::Cancelled])->save();
            $this->audit->record(AuditArea::Training, 'training.request_cancelled', "{$guard->name} cancelled a request for {$request->kind->label()}", $request, causer: $guard);

            return $request;
        });
    }
}
