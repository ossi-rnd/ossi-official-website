<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AdjustmentCategory;
use App\Enums\AuditArea;
use App\Enums\PayrollConcernStatus;
use App\Enums\PayrollRunStatus;
use App\Models\PayrollConcern;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\PayrollConcernNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounting answers a reported payslip problem, always with a reply the
 * employee reads:
 *
 *   PAY       resolved, and the amount goes on a payslip still open in a
 *             processing run, as a Correction adjustment (AddPayrollAdjustment:
 *             taxable, audited, the optional second approval)
 *   EXPLAIN   resolved, nothing to change (the payslip was right)
 *   DECLINE   not valid
 *
 * The employee is told either way.
 */
final class AnswerPayrollConcern
{
    public const PAY = 'pay';

    public const EXPLAIN = 'explain';

    public const DECLINE = 'decline';

    public function __construct(
        private readonly AddPayrollAdjustment $addAdjustment,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  float|null  $amount  signed, for PAY: + adds to pay, − takes off
     */
    public function handle(PayrollConcern $concern, User $actor, string $outcome, string $response, ?PayrollRun $payOn = null, ?float $amount = null): PayrollConcern
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        if (! in_array($outcome, [self::PAY, self::EXPLAIN, self::DECLINE], true)) {
            throw ValidationException::withMessages(['outcome' => 'Choose how it\'s answered.']);
        }

        if (mb_strlen(trim($response)) < 5) {
            throw ValidationException::withMessages(['response' => 'Write the reply the employee will read (a few words).']);
        }

        if ($outcome === self::PAY && ($payOn === null || $amount === null)) {
            throw ValidationException::withMessages(['payRunId' => 'Choose the payslip it goes on, and the amount.']);
        }

        return DB::transaction(function () use ($concern, $actor, $outcome, $response, $payOn, $amount) {
            $locked = PayrollConcern::query()->with(['user', 'run'])->lockForUpdate()->findOrFail($concern->id);

            if ($locked->status !== PayrollConcernStatus::Open) {
                throw ValidationException::withMessages(['response' => 'This concern was already answered.']);
            }

            // PAY always has a run and an amount here (checked above).
            $adjustment = $outcome === self::PAY
                ? $this->addAdjustment->handle($payOn, $locked->user, AdjustmentCategory::Correction, $amount, "Payroll concern ({$locked->run->periodLabel()}): ".trim($response), $actor)
                : null;

            $locked->forceFill([
                'status' => $outcome === self::DECLINE ? PayrollConcernStatus::Declined : PayrollConcernStatus::Resolved,
                'response' => trim($response),
                'payroll_adjustment_id' => $adjustment?->id,
                'resolved_by' => $actor->id,
                'resolved_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditArea::Payroll, 'concern.answered', "Answered {$locked->user->name}'s payroll concern ({$locked->run->periodLabel()}): {$locked->status->label()}", $locked, [
                'outcome' => $outcome,
                'amount' => $amount,
                'response' => $locked->response,
            ], causer: $actor);

            $locked->user->notify(new PayrollConcernNotice($locked, PayrollConcernNotice::ANSWERED));

            return $locked;
        });
    }

    /**
     * Where a fix can be paid: runs still processing in which this person has
     * attendance and no generated payslip yet (AddPayrollAdjustment's own rules).
     *
     * @return Collection<int, PayrollRun>
     */
    public static function payableRuns(User $employee): Collection
    {
        return PayrollRun::query()
            ->where('status', PayrollRunStatus::Processing)
            ->whereDoesntHave('payslipRecords', fn ($query) => $query->where('user_id', $employee->id))
            ->orderBy('start_date')
            ->get()
            ->filter(fn (PayrollRun $run) => $employee->timeLogs()->between($run->start_date, $run->end_date)->exists())
            ->values();
    }
}
