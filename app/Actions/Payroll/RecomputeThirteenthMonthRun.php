<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Enums\ThirteenthMonthStatus;
use App\Models\ThirteenthMonthPayout;
use App\Models\ThirteenthMonthRun;
use App\Models\User;
use App\Queries\Payroll\ThirteenthMonthBasisQuery;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refreshes a draft 13th month run from the payslips generated so far.
 */
final class RecomputeThirteenthMonthRun
{
    public function __construct(
        private readonly ThirteenthMonthBasisQuery $basis,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(ThirteenthMonthRun $run, User $actor): ThirteenthMonthRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $locked = ThirteenthMonthRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status !== ThirteenthMonthStatus::Draft) {
                throw ValidationException::withMessages(['run' => 'Only a draft can be recomputed.']);
            }

            $before = (float) $locked->payouts()->sum('amount');
            $count = $this->fill($locked);

            $this->audit->record(AuditArea::Payroll, 'thirteenth_month.recomputed', "Recomputed {$locked->label()} for {$count} employee(s)", $locked, [
                'old' => ['total' => $before],
                'new' => ['total' => (float) $locked->payouts()->sum('amount')],
            ], causer: $actor);

            return $locked;
        });
    }

    /**
     * Replaces the run's payouts with a fresh computation. Callers hold the run's lock.
     *
     * @return int how many employees it covers
     */
    public function fill(ThirteenthMonthRun $run): int
    {
        $rows = $this->basis->handle($run->year, $run->covers_until, $run->id);

        $run->payouts()->delete();

        foreach ($rows as $userId => $row) {
            $payout = new ThirteenthMonthPayout;
            $payout->forceFill([
                'thirteenth_month_run_id' => $run->id,
                'user_id' => $userId,
                'days_worked' => $row['days_worked'],
                'basic_pay' => $row['basic_pay'],
                'late_deductions' => $row['late_deductions'],
                'earned' => $row['earned'],
                'earlier_paid' => $row['earlier_paid'],
                'amount' => $row['amount'],
                'cutoffs' => $row['cutoffs'],
            ])->save();
        }

        $run->forceFill(['computed_at' => now()])->save();

        return count($rows);
    }
}
