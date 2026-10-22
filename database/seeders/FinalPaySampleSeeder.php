<?php

namespace Database\Seeders;

use App\Actions\FinalPay\PrepareFinalPay;
use App\Actions\FinalPay\SignFinalPay;
use App\Actions\FinalPay\SubmitFinalPay;
use App\Actions\FinalPay\UpdateFinalPayAdjustments;
use App\Actions\Payroll\EnrollCashBond;
use App\Actions\Payroll\RecordCashBondEntry;
use App\Actions\Payroll\SaveEmployeeDeduction;
use App\Actions\Resignations\ApplySeparations;
use App\Actions\Resignations\UpdateClearance;
use App\Enums\ClearanceItem;
use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Enums\ResignationReason;
use App\Enums\ResignationStatus;
use App\Enums\Role;
use App\Models\CashBondEntry;
use App\Models\FinalPay;
use App\Models\Resignation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Sample final pays, one at each stage, for guards who already have payslips
 * from PayrollSampleSeeder (so the 13th month has something to pro-rate):
 *
 *   guard2  last day today, consent      → ready for Accounting to prepare
 *   guard8  last day Sep 30, consent     → a draft with an adjustment, to send for signatures
 *   guard4  last day Sep 30, NO consent  → sent for signatures: Accounting signs next
 *   guard3  last day Sep 30, consent     → released, owing more than the final pay (a receivable)
 *
 * Each also has a cash bond paid in before the system (₱200 a month).
 * Safe to run again (`php artisan db:seed --class=FinalPaySampleSeeder`): a guard
 * who already has a resignation is skipped. Past resignations are written directly
 * (filing refuses past dates); everything after goes through the real Actions.
 */
class FinalPaySampleSeeder extends Seeder
{
    public function run(): void
    {
        $signers = [];
        foreach ([Role::OperationsHq, Role::Admin, Role::Hr, Role::Accounting, Role::President] as $role) {
            $signers[$role->value] = User::query()->whereHas('roles', fn ($query) => $query->where('role', $role->value))->orderBy('id')->first();
        }

        if (in_array(null, $signers, true)) {
            $this->command->warn('Operation HQ, Admin, HR, Accounting and the Executive each need an account: nothing seeded.');

            return;
        }

        /** @var array<string, User> $signers */
        $accounting = $signers[Role::Accounting->value];
        $today = CarbonImmutable::today();
        $endOfSeptember = CarbonImmutable::parse($today->year.'-09-30');

        // 1. Ready to prepare: the last day is today.
        if ($resignation = $this->resign('guard2@example.test', $today, ResignationReason::AnotherJob, 'I was offered a position closer to home in Batangas. Thank you for the years at OSSI.', true, 1800, $signers)) {
            $this->line($resignation, 'ready to prepare (last day today)');
        }

        // 2. A draft with an adjustment, for Accounting to send.
        if ($resignation = $this->resign('guard8@example.test', $endOfSeptember, ResignationReason::Family, 'I need to go home to Iloilo to take care of my mother.', true, 1800, $signers)) {
            $pay = app(PrepareFinalPay::class)->handle($resignation, $accounting);
            app(UpdateFinalPayAdjustments::class)->handle($pay, $accounting, 'Unreturned flashlight', -350, 'Not returned at turnover; agreed in writing.');
            $this->line($resignation, 'draft, with an adjustment');
        }

        // 3. Signing, without consent: the loan is shown, not deducted.
        if ($resignation = $this->resign('guard4@example.test', $endOfSeptember, ResignationReason::Health, 'My doctor advised me to stop night duty for my health.', false, 1600, $signers)) {
            $pay = app(SubmitFinalPay::class)->handle(app(PrepareFinalPay::class)->handle($resignation, $accounting), $accounting);
            $this->line($resignation, 'sent for signatures: Accounting signs next');
        }

        // 4. Released, owing more than the final pay: the rest is a receivable.
        if ($user = User::query()->where('email', 'guard3@example.test')->first()) {
            if (! $user->employeeDeductions()->where('label', 'Cash advance (Sep 2026)')->exists() && ! $user->resignations()->exists()) {
                app(SaveEmployeeDeduction::class)->handle($user, 'Cash advance (Sep 2026)', DeductionType::CashAdvance, 1000, 6000, $accounting, DeductionAuthorizationBasis::Paper);
            }
        }
        if ($resignation = $this->resign('guard3@example.test', $endOfSeptember, ResignationReason::Studies, 'I am going back to school this semester to finish my criminology degree.', true, 1400, $signers)) {
            $pay = app(SubmitFinalPay::class)->handle(app(PrepareFinalPay::class)->handle($resignation, $accounting), $accounting);
            // In the signing order: Accounting, then the Executive (which releases it).
            foreach (FinalPay::SIGNERS as $role) {
                app(SignFinalPay::class)->handle($pay, $signers[$role->value]);
            }
            $this->line($resignation, 'released, with a receivable');
        }

        // The day after each past last day: Resigned, accounts closed.
        app(ApplySeparations::class)->handle($today);
    }

    /**
     * An accepted resignation (30 days' notice), with the cash bond paid in before
     * the system and the clearance ticked, except loans (final pay settles them) and final pay itself.
     *
     * @param  array<string, User>  $signers
     */
    private function resign(string $email, CarbonImmutable $lastDay, ResignationReason $reason, string $letter, bool $consent, float $bondPaidBefore, array $signers): ?Resignation
    {
        $user = User::query()->where('email', $email)->with('employeeProfile')->first();

        if ($user === null || $user->resignations()->exists()) {
            $this->command->line("Skipped {$email}: no such account, or it already has a resignation.");

            return null;
        }

        $hr = $signers[Role::Hr->value];
        $filed = $lastDay->subDays(Resignation::NOTICE_DAYS)->setTime(9, 0);

        $resignation = new Resignation;
        $resignation->forceFill([
            'user_id' => $user->id,
            'reason' => $reason,
            'letter' => $letter,
            'requested_last_day' => $lastDay->toDateString(),
            'status' => ResignationStatus::Accepted,
            'last_day' => $lastDay->toDateString(),
            'decided_by' => $hr->id,
            'decided_at' => $filed->addDay(),
            'deduction_consent_at' => $consent ? $filed : null,
            'created_at' => $filed,
            'updated_at' => $filed->addDay(),
        ])->save();

        app(EnrollCashBond::class)->handle($user, $signers[Role::Accounting->value]);
        if (! CashBondEntry::query()->where('user_id', $user->id)->where('kind', CashBondEntry::OPENING)->exists()) {
            app(RecordCashBondEntry::class)->handle($user, CashBondEntry::OPENING, $bondPaidBefore, 'From the old cash bond ledger (₱200 a month)', $signers[Role::Accounting->value]);
        }

        foreach (ClearanceItem::for(true) as $item) {
            if (! in_array($item, [ClearanceItem::FinalPay, ClearanceItem::LoansSettled], true) && ! ($item === ClearanceItem::Certificate && $lastDay->isToday())) {
                $resignation = app(UpdateClearance::class)->handle($resignation, $item, true, $hr);
            }
        }

        return $resignation;
    }

    private function line(Resignation $resignation, string $stage): void
    {
        $this->command->info("{$resignation->user->name}: last day {$resignation->effectiveLastDay()->format('M j')}, {$stage}.");
    }
}
