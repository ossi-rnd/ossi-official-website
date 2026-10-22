<?php

namespace App\Actions\Payroll;

use App\Enums\AdjustmentCategory;
use App\Enums\AdjustmentStatus;
use App\Enums\AuditArea;
use App\Enums\ReferralStatus;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\ReferralNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;

/**
 * Called by GeneratePayslip, inside its transaction, before the figures are computed:
 * each of this employee's referrals whose bonus fell due by the end of the cut-off
 * becomes a Referral bonus line on this payslip, if the hire stayed that long
 * (Referral::hireStayed); if they didn't, the referral is closed without a bonus and
 * the referrer told. Set by HR's terms, so it's added as approved (no second check).
 */
final class PayDueReferralBonuses
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return int how many bonuses were added
     */
    public function handle(PayrollRun $run, User $employee, User $actor): int
    {
        $added = 0;
        $cutOff = CarbonImmutable::parse($run->end_date);

        $due = Referral::query()->with('hire')->where('referrer_id', $employee->id)->where('status', ReferralStatus::Hired)
            ->whereDate('bonus_due_on', '<=', $cutOff->toDateString())->lockForUpdate()->orderBy('id')->get();

        foreach ($due as $referral) {
            if ($referral->hireStayed($cutOff) !== true) {
                $referral->forceFill(['status' => ReferralStatus::Forfeited])->save();
                $this->audit->record(AuditArea::People, 'referral.forfeited', "No referral bonus for {$employee->name}: {$referral->applicant_name} left before {$referral->bonus_due_on?->format('M j, Y')}", $referral, causer: $actor);
                $employee->notify(new ReferralNotice($referral, ReferralNotice::FORFEITED));

                continue;
            }

            if ((float) $referral->bonus_amount <= 0) {
                $referral->forceFill(['status' => ReferralStatus::Paid, 'paid_in_run_id' => $run->id])->save();

                continue;
            }

            $adjustment = new PayrollAdjustment;
            $adjustment->forceFill([
                'payroll_run_id' => $run->id,
                'user_id' => $employee->id,
                'category' => AdjustmentCategory::ReferralBonus,
                'amount' => $referral->bonus_amount,
                'reason' => "Referred {$referral->applicant_name}, hired ".$referral->hire?->hire_date?->format('M j, Y'),
                'status' => AdjustmentStatus::Approved,
                'created_by' => $actor->id,
                'reviewed_by' => $referral->decided_by,
                'reviewed_at' => $referral->decided_at ?? CarbonImmutable::now(),
            ])->save();

            $referral->forceFill(['status' => ReferralStatus::Paid, 'payroll_adjustment_id' => $adjustment->id, 'paid_in_run_id' => $run->id])->save();
            $this->audit->record(AuditArea::Payroll, 'referral.paid', "Paid {$employee->name}'s referral bonus of ₱".number_format((float) $referral->bonus_amount, 2)." on {$run->periodLabel()}", $referral, causer: $actor);
            $added++;
        }

        return $added;
    }
}
