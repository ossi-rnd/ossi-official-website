<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\PaidLeavePayment;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting dismisses a paid-leave suggestion with a reason (e.g. already paid
 * another way, or the leave wasn't actually taken). Never suggested again for
 * this run; the days stay available to a later run.
 */
final class DismissPaidLeave
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PaidLeavePayment $payment, User $actor, string $reason): PaidLeavePayment
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['dismissReason' => 'Say why it isn\'t paid (a few words).']);
        }

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $locked = PaidLeavePayment::query()->with(['run', 'user', 'leaveRequest'])->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status !== PaidLeavePayment::SUGGESTED) {
                throw ValidationException::withMessages(['paidLeave' => 'This was already decided.']);
            }

            $locked->forceFill(['status' => PaidLeavePayment::DISMISSED, 'dismiss_reason' => trim($reason), 'decided_by' => $actor->id, 'decided_at' => CarbonImmutable::now()])->save();
            $this->audit->record(AuditArea::Payroll, 'paid_leave.dismissed', "Didn't pay {$locked->user->name}'s {$locked->leaveRequest->type->label()} on {$locked->run->periodLabel()}: {$locked->dismiss_reason}", $locked, causer: $actor);

            return $locked;
        });
    }
}
