<?php

namespace Database\Seeders;

use App\Actions\Payroll\AddPayrollAdjustment;
use App\Actions\Payroll\AdvancePayrollRun;
use App\Actions\Payroll\CreatePayrollRun;
use App\Actions\Payroll\GenerateRunPayslips;
use App\Actions\Payroll\SaveEmployeeDeduction;
use App\Enums\AdjustmentCategory;
use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Enums\LoadRequestStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\Position;
use App\Enums\Role;
use App\Models\EmployeeDeduction;
use App\Models\LoadRequest;
use App\Models\PayrollRun;
use App\Models\PositionRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Sample payroll: daily rates, loans and other deductions, borrowed load,
 * adjustments, and generated payslips for the last two finished cut-offs.
 *
 * Safe on a database that already has data (`php artisan db:seed
 * --class=PayrollSampleSeeder`): it only adds what's missing, and it never
 * touches a run that is already approved or paid. Everything goes through
 * the real Actions, so the payslips are computed exactly like production ones.
 *
 *   cut-off before last → generated, approved and paid
 *   last cut-off        → generated and approved (ready to mark paid)
 *   current cut-off     → processing, so employees see estimates
 */
class PayrollSampleSeeder extends Seeder
{
    /** Company rates per position, following the NCR wage orders for guards. */
    private const POSITION_RATES = [
        [Position::SecurityGuard, '2025-07-17', 695, 'Wage Order NCR-26'],
        [Position::SecurityGuard, '2026-09-26', 755, 'Wage Order NCR-28'],
        [Position::ShiftInCharge, '2025-07-17', 800, '2025 rate card'],
        [Position::AdminStaff, '2025-01-01', 900, 'Office rate'],
        [Position::HrStaff, '2025-01-01', 900, 'Office rate'],
        [Position::AccountingStaff, '2025-01-01', 900, 'Office rate'],
        [Position::ItStaff, '2025-01-01', 950, 'Office rate'],
        [Position::OperationsHead, '2025-01-01', 1500, 'Office rate'],
        [Position::SatelliteOperationsOfficer, '2025-01-01', 1100, 'Office rate'],
    ];

    /** [guard index, label, type, per payslip, total (null = every payslip)] */
    private const DEDUCTIONS = [
        [0, 'SSS salary loan', DeductionType::Loan, 500, 6000],
        [1, 'Cash advance (Sep 2026)', DeductionType::CashAdvance, 1000, 2000],
        [2, 'Uniform', DeductionType::Uniform, 150, null],
        [3, 'Pag-IBIG multi-purpose loan', DeductionType::Loan, 400, 4800],
        [4, 'Radio rental', DeductionType::Equipment, 100, null],
        [5, 'Cooperative share capital', DeductionType::Other, 200, null],
        [6, 'Cash advance (hospital bill)', DeductionType::CashAdvance, 750, 1500],
        [7, 'Company emergency loan', DeductionType::Loan, 1000, 5000],
    ];

    /** [guard index, network, amount]: approved before payroll, so the payslip deducts them. */
    private const LOADS = [
        [8, 'globe', 300],
        [9, 'smart', 100],
    ];

    /** [guard index, category, signed amount, reason]: added to each generated run. */
    private const ADJUSTMENTS = [
        [1, AdjustmentCategory::Allowance, 500, 'Transport allowance for relief duty at BGC.'],
        [7, AdjustmentCategory::Allowance, 300, 'Meal allowance as shift-in-charge.'],
        [11, AdjustmentCategory::Penalty, -200, 'Lost company ID; replacement cost agreed in writing.'],
        [12, AdjustmentCategory::Correction, 350, 'Missed clock-in confirmed by the detachment commander.'],
    ];

    public function run(): void
    {
        $actor = User::query()->whereHas('roles', fn ($query) => $query->where('role', Role::Accounting->value))->orderBy('id')->first()
            ?? User::query()->whereHas('roles', fn ($query) => $query->where('role', Role::SuperAdmin->value))->orderBy('id')->first();

        if ($actor === null) {
            $this->command->warn('No Accounting or Super Admin account: nothing seeded.');

            return;
        }

        /** @var Collection<int, User> $guards */
        $guards = User::query()->onPayroll()->whereHas('roles', fn ($query) => $query->where('role', Role::Employee->value))->orderBy('id')->get()->values();

        $this->positionRates();
        $this->deductions($guards, $actor);
        $this->loads($guards, $actor);

        $today = CarbonImmutable::today();
        $current = $this->cutoff($today);
        $previous = $this->cutoff($current[0]->subDay());
        $beforePrevious = $this->cutoff($previous[0]->subDay());

        $this->payRun($beforePrevious, $guards, $actor, PayrollRunStatus::Paid);
        $this->payRun($previous, $guards, $actor, PayrollRunStatus::Approved);

        // The current cut-off stays processing: employees see live estimates.
        if (($run = $this->findOrCreateRun($current, $actor)) !== null && $run->status === PayrollRunStatus::Draft) {
            app(AdvancePayrollRun::class)->handle($run, $actor);
        }
    }

    /**
     * The company rate for each position that has none yet.
     */
    private function positionRates(): void
    {
        $existing = PositionRate::query()->pluck('position')->map(fn ($position) => $position instanceof Position ? $position->value : $position)->unique()->all();

        foreach (self::POSITION_RATES as [$position, $from, $amount, $note]) {
            if (in_array($position->value, $existing, true)) {
                continue;
            }

            $rate = new PositionRate;
            $rate->forceFill(['position' => $position, 'daily_rate' => $amount, 'effective_from' => $from, 'note' => $note])->save();
        }
    }

    /**
     * @param  Collection<int, User>  $guards
     */
    private function deductions(Collection $guards, User $actor): void
    {
        foreach (self::DEDUCTIONS as [$index, $label, $type, $installment, $total]) {
            $guard = $guards->get($index);

            if ($guard === null || $guard->employeeDeductions()->where('label', $label)->exists()) {
                continue;
            }

            // Sample loans already exist on paper; SSS and Pag-IBIG salary loans are deducted by law.
            $basis = str_contains($label, 'SSS') || str_contains($label, 'Pag-IBIG') ? DeductionAuthorizationBasis::Law : DeductionAuthorizationBasis::Paper;
            app(SaveEmployeeDeduction::class)->handle($guard, $label, $type, $installment, $total, $actor, $basis);
        }
    }

    /**
     * Approved load on credit, written directly like DemoSeeder's: approving
     * through ReviewLoadRequest would need a proof screenshot and send email.
     *
     * @param  Collection<int, User>  $guards
     */
    private function loads(Collection $guards, User $admin): void
    {
        foreach (self::LOADS as [$index, $network, $amount]) {
            $guard = $guards->get($index);

            if ($guard === null || LoadRequest::query()->where('user_id', $guard->id)->exists()) {
                continue;
            }

            $at = CarbonImmutable::today()->subDays(30)->setTime(9, 0);
            $loadRequest = new LoadRequest;
            $loadRequest->forceFill([
                'user_id' => $guard->id,
                'network' => $network,
                'mobile_number' => sprintf('0917%07d', 5550000 + $index),
                'amount' => $amount,
                'status' => LoadRequestStatus::Approved,
                'created_at' => $at,
                'updated_at' => $at,
            ])->save();

            $deduction = new EmployeeDeduction(['label' => $loadRequest->deductionLabel(), 'type' => DeductionType::Load, 'installment_amount' => $amount, 'original_amount' => $amount]);
            $deduction->user()->associate($guard);
            $deduction->remaining_balance = (string) $amount;
            $deduction->save();

            $loadRequest->forceFill(['approved_by' => $admin->id, 'approved_at' => $at->addHour(), 'employee_deduction_id' => $deduction->id])->save();
        }
    }

    /**
     * Brings one finished cut-off to $target: processing → adjustments →
     * payslips → approved (→ paid). Runs already past processing are left as they are.
     *
     * @param  array{CarbonImmutable, CarbonImmutable}  $period
     * @param  Collection<int, User>  $guards
     */
    private function payRun(array $period, Collection $guards, User $actor, PayrollRunStatus $target): void
    {
        $run = $this->findOrCreateRun($period, $actor);

        if ($run === null || ! $run->status->isOpen()) {
            return;
        }

        $advance = app(AdvancePayrollRun::class);

        if ($run->status === PayrollRunStatus::Draft) {
            $run = $advance->handle($run, $actor);
        }

        foreach (self::ADJUSTMENTS as [$index, $category, $amount, $reason]) {
            $guard = $guards->get($index);

            if ($guard === null || $guard->payrollAdjustments()->where('payroll_run_id', $run->id)->exists()) {
                continue;
            }

            try {
                app(AddPayrollAdjustment::class)->handle($run, $guard, $category, $amount, $reason, $actor);
            } catch (ValidationException) {
                // No attendance in this period, or the payslip is already generated.
            }
        }

        $generated = app(GenerateRunPayslips::class)->handle($run, $actor, $skippedNoRate);
        $this->command->info("{$run->periodLabel()}: {$generated} payslips generated".($skippedNoRate > 0 ? ", {$skippedNoRate} skipped (no daily rate)" : '').'.');

        try {
            while ($run->status !== $target && $run->status->next() !== null) {
                $run = $advance->handle($run, $actor);
            }
        } catch (ValidationException $exception) {
            $this->command->warn("{$run->periodLabel()} left as {$run->status->label()}: ".collect($exception->errors())->flatten()->first());
        }
    }

    /**
     * The run for exactly this period, created if no run overlaps it.
     *
     * @param  array{CarbonImmutable, CarbonImmutable}  $period
     */
    private function findOrCreateRun(array $period, User $actor): ?PayrollRun
    {
        [$start, $end] = $period;

        $run = PayrollRun::query()->whereDate('start_date', $start->toDateString())->whereDate('end_date', $end->toDateString())->first();

        if ($run !== null) {
            return $run;
        }

        try {
            return app(CreatePayrollRun::class)->handle($start, $end, $end->addDays(5), $actor);
        } catch (ValidationException) {
            $this->command->warn("Skipped {$start->format('M j')}–{$end->format('M j')}: another payroll run overlaps it.");

            return null;
        }
    }

    /**
     * The semi-monthly cut-off containing $date: the 1st–15th or the 16th–end of month.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function cutoff(CarbonImmutable $date): array
    {
        return $date->day <= 15
            ? [$date->startOfMonth(), $date->setDay(15)]
            : [$date->setDay(16), $date->endOfMonth()->startOfDay()];
    }
}
