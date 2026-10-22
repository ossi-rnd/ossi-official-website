<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AdjustmentCategory;
use App\Enums\AuditArea;
use App\Models\PaidLeavePayment;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting accepts a paid-leave suggestion: a "Paid leave" adjustment
 * (days × daily rate, taxable), or for maternity the SSS benefit advance Accounting
 * enters (an Allowance: not taxable), through AddPayrollAdjustment, so the usual
 * rules hold. Accounting may pay fewer days than suggested (e.g. a rest day inside
 * the leave).
 */
final class AcceptPaidLeave
{
    public function __construct(
        private readonly AddPayrollAdjustment $addAdjustment,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PaidLeavePayment $payment, User $actor, ?int $days = null, ?float $sssAmount = null): PaidLeavePayment
    {
        $this->authorizeFor($actor);

        return DB::transaction(function () use ($payment, $actor, $days, $sssAmount) {
            $locked = $this->lockedSuggestion($payment);
            $type = $locked->leaveRequest->type;
            $days ??= $locked->days;

            if ($days < 1 || $days > $locked->days) {
                throw ValidationException::withMessages(['paidLeave' => "From 1 to {$locked->days} days."]);
            }

            $period = $locked->leaveRequest->dateRangeLabel();

            if ($type->paidAsSssBenefit()) {
                if ($sssAmount === null || $sssAmount <= 0) {
                    throw ValidationException::withMessages(['paidLeave' => 'Enter the SSS maternity benefit to advance (from the SSS computation).']);
                }

                $adjustment = $this->addAdjustment->handle($locked->run, $locked->user, AdjustmentCategory::Allowance, $sssAmount, "SSS maternity benefit advance ({$type->law()}), leave {$period}", $actor, forPaidLeave: true);
                $amount = $sssAmount;
            } else {
                $amount = round($days * (float) $locked->daily_rate, 2);
                $adjustment = $this->addAdjustment->handle($locked->run, $locked->user, AdjustmentCategory::PaidLeave, $amount, "{$type->label()} ({$type->law()}): {$days} ".str('day')->plural($days).' × ₱'.number_format((float) $locked->daily_rate, 2).", leave {$period}", $actor, forPaidLeave: true);
            }

            $locked->forceFill(['status' => PaidLeavePayment::ACCEPTED, 'days' => $days, 'amount' => $amount, 'payroll_adjustment_id' => $adjustment->id, 'decided_by' => $actor->id, 'decided_at' => CarbonImmutable::now()])->save();
            $this->audit->record(AuditArea::Payroll, 'paid_leave.accepted', "Paid {$locked->user->name}'s {$type->label()} on {$locked->run->periodLabel()}: ₱".number_format($amount, 2), $locked, ['days' => $days], causer: $actor);

            return $locked;
        });
    }

    private function authorizeFor(User $actor): void
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }
    }

    private function lockedSuggestion(PaidLeavePayment $payment): PaidLeavePayment
    {
        $locked = PaidLeavePayment::query()->with(['run', 'user', 'leaveRequest'])->lockForUpdate()->findOrFail($payment->id);

        if ($locked->status !== PaidLeavePayment::SUGGESTED) {
            throw ValidationException::withMessages(['paidLeave' => 'This was already decided.']);
        }

        return $locked;
    }
}
