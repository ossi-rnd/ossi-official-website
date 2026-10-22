<?php

namespace App\Actions\Complaints;

use App\Enums\AuditArea;
use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\User;
use App\Notifications\ComplaintNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Its handler (HR, or the President or Vice President for HR staff's) moves a complaint along. Closing it (resolved or dismissed) needs the
 * outcome in writing, which the employee sees. Nobody handles a complaint
 * they filed themselves.
 */
final class UpdateComplaint
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Complaint $complaint, ComplaintStatus $status, ?string $resolution, User $actor): Complaint
    {
        // HR can't touch one filed by HR staff (the President or Vice President's), nor the President or Vice President HR's other cases.
        if (! $actor->can($complaint->handlerAbility()->value)) {
            throw new AuthorizationException;
        }

        if ($complaint->user_id === $actor->id) {
            throw ValidationException::withMessages(['status' => 'You filed this complaint, so someone else handles it.']);
        }

        if ($status->isClosed() && mb_strlen(trim((string) $resolution)) < 10) {
            throw ValidationException::withMessages(['resolution' => 'Write the outcome for the employee (at least a sentence) before closing it.']);
        }

        return DB::transaction(function () use ($complaint, $status, $resolution, $actor) {
            $locked = Complaint::query()->with('user')->lockForUpdate()->findOrFail($complaint->id);
            $before = $locked->status;

            $locked->forceFill([
                'status' => $status,
                'handled_by' => $actor->id,
                'resolution' => filled($resolution) ? trim((string) $resolution) : null,
                'closed_at' => $status->isClosed() ? ($locked->closed_at ?? now()) : null,
            ])->save();

            $this->audit->record(AuditArea::Complaints, 'complaint.updated', "Complaint {$locked->reference()}: {$status->label()}", $locked, [
                'old' => ['status' => $before->value], 'new' => ['status' => $status->value],
            ], causer: $actor);

            if ($before !== $status) {
                $locked->user->notify(new ComplaintNotice($locked, forHr: false));
            }

            return $locked;
        });
    }
}
