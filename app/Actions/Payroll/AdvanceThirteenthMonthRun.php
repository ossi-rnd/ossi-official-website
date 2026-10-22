<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Enums\ThirteenthMonthStatus;
use App\Models\ThirteenthMonthRun;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Queries\Payroll\ThirteenthMonthBasisQuery;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * draft → approved → paid.
 *
 *  - approving re-checks the numbers against the payslips: if a payslip was
 *    generated since the last compute, the draft is refreshed and nothing is
 *    approved, so what Accounting approves is what they saw;
 *  - marking paid stamps paid_at on every payout.
 */
final class AdvanceThirteenthMonthRun
{
    public function __construct(
        private readonly ThirteenthMonthBasisQuery $basis,
        private readonly RecomputeThirteenthMonthRun $recompute,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(ThirteenthMonthRun $run, User $actor): ThirteenthMonthRun
    {
        // Outside the approval transaction: the refresh must stay saved when approval is refused.
        $changed = DB::transaction(function () use ($run) {
            $locked = ThirteenthMonthRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status !== ThirteenthMonthStatus::Draft || ($changed = $this->changedSinceCompute($locked)) === 0) {
                return 0;
            }

            $this->recompute->fill($locked);

            return $changed;
        });

        if ($changed > 0) {
            throw ValidationException::withMessages(['run' => "{$changed} employee(s) changed since the last compute (new payslips). The amounts are updated: review them and approve again."]);
        }

        return DB::transaction(function () use ($run, $actor) {
            $locked = ThirteenthMonthRun::query()->lockForUpdate()->findOrFail($run->id);
            $from = $locked->status;
            $to = $from->next();

            if ($to === null) {
                throw ValidationException::withMessages(['run' => 'This 13th month run is already paid.']);
            }

            $locked->status = $to;
            $locked->save();

            if ($to === ThirteenthMonthStatus::Paid) {
                $locked->payouts()->whereNull('paid_at')->update(['paid_at' => now()]);

                // Everyone paid hears it, with their own amount.
                foreach ($locked->payouts()->with('user')->get() as $payout) {
                    UpdateNotice::tell($payout->user, $actor, '13th month pay released: ₱'.number_format((float) $payout->amount, 2),
                        "Your {$locked->label()}. See it on My Payslips.", route('payslips'), 'gift', email: true);
                }
            }

            $this->audit->record(AuditArea::Payroll, 'thirteenth_month.'.$to->value, "Moved {$locked->label()} to {$to->label()}", $locked, [
                'old' => ['status' => $from->value],
                'new' => ['status' => $to->value],
                'total' => (float) $locked->payouts()->sum('amount'),
            ], causer: $actor);

            return $locked;
        });
    }

    private function changedSinceCompute(ThirteenthMonthRun $run): int
    {
        $fresh = $this->basis->handle($run->year, $run->covers_until, $run->id);
        $stored = $run->payouts()->pluck('amount', 'user_id')->map(fn ($amount) => (float) $amount)->all();

        $changed = count(array_diff_key($stored, $fresh));

        foreach ($fresh as $userId => $row) {
            if (! array_key_exists($userId, $stored) || abs($stored[$userId] - $row['amount']) > 0.004) {
                $changed++;
            }
        }

        return $changed;
    }
}
