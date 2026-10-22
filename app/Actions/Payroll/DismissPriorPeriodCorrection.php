<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\PriorPeriodCorrection;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting decides a suggested correction shouldn't be paid (e.g. it was
 * already paid in cash, or the change was an entry mistake), with a reason. It
 * counts as settled: the same difference isn't suggested again.
 */
final class DismissPriorPeriodCorrection
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PriorPeriodCorrection $correction, User $actor, string $reason): PriorPeriodCorrection
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['dismissReason' => 'Say why it isn\'t paid (a few words).']);
        }

        return DB::transaction(function () use ($correction, $actor, $reason) {
            $locked = PriorPeriodCorrection::query()->with(['run', 'fromRun', 'user'])->lockForUpdate()->findOrFail($correction->id);

            if ($locked->status !== PriorPeriodCorrection::SUGGESTED) {
                throw ValidationException::withMessages(['correction' => 'This correction was already decided.']);
            }

            $locked->forceFill([
                'status' => PriorPeriodCorrection::DISMISSED,
                'dismiss_reason' => trim($reason),
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditArea::Payroll, 'correction.dismissed', "Dismissed a correction from {$locked->fromRun->periodLabel()} for {$locked->user->name}", $locked, [
                'amount' => (float) $locked->amount,
                'reason' => $locked->dismiss_reason,
            ], causer: $actor);

            return $locked;
        });
    }
}
