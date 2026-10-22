<?php

namespace App\Actions\Load;

use App\Enums\AuditArea;
use App\Enums\LoadRequestStatus;
use App\Models\LoadRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The employee withdraws a request while it's still waiting. Once approved,
 * the load is sent and the deduction exists, so it can't be taken back here.
 */
final class CancelLoadRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(LoadRequest $loadRequest, User $actor): void
    {
        if ($loadRequest->user_id !== $actor->id) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($loadRequest, $actor) {
            $locked = LoadRequest::query()->whereKey($loadRequest->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canMoveTo(LoadRequestStatus::Cancelled)) {
                throw ValidationException::withMessages(['cancel' => 'This request can no longer be cancelled.']);
            }

            $locked->forceFill(['status' => LoadRequestStatus::Cancelled])->save();

            $this->audit->record(AuditArea::Load, 'load.cancelled', "Cancelled ₱{$locked->amount} {$locked->networkLabel()} load request", $locked, causer: $actor);
        });
    }
}
