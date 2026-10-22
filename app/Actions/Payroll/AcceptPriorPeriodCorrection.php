<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AdjustmentCategory;
use App\Enums\AuditArea;
use App\Models\PriorPeriodCorrection;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting accepts a correction from an earlier cut-off: it becomes a
 * Correction adjustment on this run's payslip (taxable, like the pay it
 * replaces), through AddPayrollAdjustment, so the same rules hold, including
 * the optional second approval for large amounts.
 */
final class AcceptPriorPeriodCorrection
{
    public function __construct(
        private readonly AddPayrollAdjustment $addAdjustment,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PriorPeriodCorrection $correction, User $actor): PriorPeriodCorrection
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($correction, $actor) {
            $locked = PriorPeriodCorrection::query()->with(['run', 'fromRun', 'user'])->lockForUpdate()->findOrFail($correction->id);

            if ($locked->status !== PriorPeriodCorrection::SUGGESTED) {
                throw ValidationException::withMessages(['correction' => 'This correction was already decided.']);
            }

            $adjustment = $this->addAdjustment->handle($locked->run, $locked->user, AdjustmentCategory::Correction, (float) $locked->amount, $locked->adjustmentReason(), $actor);

            $locked->forceFill([
                'status' => PriorPeriodCorrection::ACCEPTED,
                'payroll_adjustment_id' => $adjustment->id,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditArea::Payroll, 'correction.accepted', "Accepted a correction from {$locked->fromRun->periodLabel()} on {$locked->user->name}'s payslip for {$locked->run->periodLabel()}", $locked, [
                'amount' => (float) $locked->amount,
                'differences' => $locked->differences,
            ], causer: $actor);

            return $locked;
        });
    }
}
