<?php

namespace App\Livewire\Admin;

use App\Actions\Payroll\AdvancePayrollRun;
use App\Actions\Payroll\CreatePayrollRun;
use App\Actions\Payroll\DeleteEmployeeDeduction;
use App\Actions\Payroll\GeneratePayslip;
use App\Actions\Payroll\RecordPayslipView;
use App\Actions\Payroll\SaveEmployeeDeduction;
use App\Actions\Payroll\StartPayslipGeneration;
use App\Actions\Payroll\ToggleEmployeeDeduction;
use App\Data\LateDay;
use App\Data\PayslipBreakdown;
use App\Enums\Ability;
use App\Enums\DeductionAuthorizationBasis;
use App\Enums\DeductionType;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeDeduction;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Models\PayslipRecord;
use App\Models\User;
use App\Queries\Attendance\AttendanceHistoryQuery;
use App\Queries\Payroll\LateDaysQuery;
use App\Queries\Payroll\PayrollOverviewQuery;
use App\Queries\Payroll\ResolvePayslips;
use App\Queries\People\EffectiveRatesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll runs, their payslips and employees' loan deductions.
 *
 * Every write re-checks `manage-payroll` itself. The route's `can:` is
 * real protection, but it lives in routes/web.php; if this component were
 * ever mounted somewhere else, the check here still holds.
 *
 * Scale: totals on the run list come from SQL sums over generated records;
 * live estimates are only computed for the 20 employees on the current page.
 *
 * @property-read Collection<int, PayrollRun> $runs
 * @property-read PayrollRun|null $selectedRun
 *
 * @phpstan-import-type PayrollOverview from PayrollOverviewQuery
 *
 * @property-read PayrollOverview $stats
 * @property-read LengthAwarePaginator<int, User> $employees
 * @property-read array<int, PayslipBreakdown> $pagePayslips
 * @property-read int $missingPayslips
 * @property-read string|null $approvalBlockedReason
 * @property-read User|null $selectedEmployee
 * @property-read PayslipBreakdown|null $selectedPayslip
 * @property-read PayslipRecord|null $selectedRecord
 * @property-read int $pendingAdjustments
 * @property-read string|null $loansLockedReason
 * @property-read array{count: int, names: list<string>}|null $noRateWarning
 * @property-read array<string, int> $selectedAttendance
 * @property-read list<LateDay> $selectedLateDays
 * @property-read Collection<int, EmployeeDeduction> $deductions
 */
#[Title('Payroll')]
class Payroll extends Component
{
    use InteractsWithUser, WithPagination;

    #[Url(as: 'run')]
    public ?int $selectedRunId = null;

    public ?int $selectedUserId = null;

    /** payslips | contributions: the two tabs of a run. */
    #[Url(as: 'view')]
    public string $runView = 'payslips';

    public bool $showCreateRun = false;

    public string $startDate = '';

    public string $endDate = '';

    public string $payDate = '';

    public string $deductionLabel = '';

    public string $deductionType = 'loan';

    public string $deductionInstallment = '';

    public string $deductionTotal = '';

    /** How it's authorized (Art. 113): asked in the app by default. */
    public string $deductionBasis = 'employee';

    // ---- Navigation -------------------------------------------------------

    public function viewRun(int $runId): void
    {
        $this->selectedRunId = PayrollRun::query()->findOrFail($runId)->id;
        $this->selectedUserId = null;
        $this->resetPage();
    }

    public function backToRuns(): void
    {
        $this->reset(['selectedRunId', 'selectedUserId']);
    }

    public function viewPayslip(int $userId, RecordPayslipView $recordView): void
    {
        $employee = User::query()->onPayroll()->findOrFail($userId);
        $this->selectedUserId = $employee->id;
        $this->resetDeductionForm();

        if ($this->selectedRun !== null) {
            $recordView->handle($this->selectedRun, $employee, $this->currentUser());
        }
    }

    public function closePayslip(): void
    {
        $this->selectedUserId = null;
        $this->resetDeductionForm();
    }

    public function openCreateRun(): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        // Suggest the next semi-monthly cut-off after the latest run.
        $latest = PayrollRun::query()->orderByDesc('end_date')->first();
        $start = $latest !== null ? $latest->end_date->addDay() : CarbonImmutable::today()->startOfMonth();
        $end = $start->day <= 15 ? $start->setDay(15) : $start->endOfMonth()->startOfDay();

        $this->startDate = $start->toDateString();
        $this->endDate = $end->toDateString();
        $this->payDate = $end->addDays(5)->toDateString();
        $this->resetErrorBag();
        $this->showCreateRun = true;
    }

    // ---- Writes (each one re-authorizes) ----------------------------------

    public function createRun(CreatePayrollRun $createRun): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $this->validate([
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
            'payDate' => ['required', 'date'],
        ]);

        $run = $createRun->handle(CarbonImmutable::parse($this->startDate), CarbonImmutable::parse($this->endDate), CarbonImmutable::parse($this->payDate), $this->currentUser());

        $this->showCreateRun = false;
        unset($this->runs);
        $this->viewRun($run->id);
        $this->dispatch('notify', message: 'Payroll run created as a draft.');
    }

    public function advanceRun(AdvancePayrollRun $advance): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $run = $advance->handle($this->selectedRunOrFail(), $this->currentUser());

        $this->clearComputed();
        $this->dispatch('notify', message: "Run moved to {$run->status->label()}.");
    }

    public function generate(int $userId, GeneratePayslip $generatePayslip): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $employee = User::query()->onPayroll()->findOrFail($userId);
        $generatePayslip->handle($this->selectedRunOrFail(), $employee, $this->currentUser());

        $this->clearComputed();
        $this->dispatch('notify', message: "Payslip generated for {$employee->name}.");
    }

    /**
     * "Generate all": in the background (StartPayslipGeneration → GenerateRunPayslipsJob), so
     * thousands of payslips don't hang this page. The page shows a progress bar while it runs.
     */
    public function generateAll(StartPayslipGeneration $start): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $run = $start->handle($this->selectedRunOrFail(), $this->currentUser());

        $this->clearComputed();
        $this->dispatch('notify', message: $run->generation_status === 'done'
            ? $this->generationSummary($run)
            : 'Generating payslips in the background. You can leave this page; you\'ll get a bell when it\'s done.');
    }

    /** The progress bar polls this while a generation runs. */
    public function refreshGeneration(): void
    {
        $this->clearComputed();
    }

    public function generationSummary(PayrollRun $run): string
    {
        $waiting = $this->pendingAdjustments;
        $message = match (true) {
            $run->generation_generated > 0 => "Generated {$run->generation_generated} payslip(s).",
            $run->generation_skipped_no_rate > 0 => 'No payslips generated.',
            default => 'Every employee who worked this period already has a payslip.',
        };
        $message .= $waiting > 0 ? " {$waiting} skipped: adjustment awaiting approval." : '';

        return $message.($run->generation_skipped_no_rate > 0 ? " {$run->generation_skipped_no_rate} without a daily rate skipped: set it on Daily Rates." : '');
    }

    /**
     * Employees on payroll with no daily rate (no personal or position rate,
     * and a ₱0 company default). Their payslips can't be generated until
     * Accounting sets one, so the open run names them.
     *
     * @return array{count: int, names: list<string>}|null
     */
    #[Computed]
    public function noRateWarning(): ?array
    {
        if ($this->selectedRun === null || ! $this->selectedRun->status->isOpen()) {
            return null;
        }

        $employees = User::query()->onPayroll()->with('employeeProfile')->orderBy('name')->get(['id', 'name']);
        $paid = app(EffectiveRatesQuery::class)->handle($employees->map(fn (User $user) => $user->employeeProfile)->filter()->values()->all(), $this->selectedRun->start_date);
        $none = $employees->filter(fn (User $user) => ($paid[$user->employeeProfile->id ?? 0]->rate ?? 0.0) <= 0);

        return $none->isEmpty() ? null : [
            'count' => $none->count(),
            'names' => array_values($none->take(5)->map(fn (User $user) => $user->name)->all()),
        ];
    }

    /**
     * Adjustments in this run still waiting for the second person.
     */
    #[Computed]
    public function pendingAdjustments(): int
    {
        return $this->selectedRunId !== null
            ? PayrollAdjustment::query()->where('payroll_run_id', $this->selectedRunId)->pending()->distinct()->count('user_id')
            : 0;
    }

    /**
     * The adjustments panel inside the payslip modal changed something: the
     * payslip, the run totals and the "missing" count must all be re-read.
     */
    #[On('adjustments-changed')]
    public function refreshAfterAdjustment(): void
    {
        $this->clearComputed();
    }

    /**
     * Loans belong to the employee and apply to FUTURE payslips, so they are
     * managed from a payslip that can still change. From a generated or paid
     * payslip it would look like editing that payslip, which it never does.
     */
    #[Computed]
    public function loansLockedReason(): ?string
    {
        if ($this->selectedRun === null || ! $this->selectedRun->status->isOpen()) {
            return "This run is {$this->selectedRun?->status->label()}, so its payslips are final. Loans apply to future payslips: add or change them from an open payroll run.";
        }

        return $this->selectedRecord !== null
            ? 'This payslip is generated and frozen. Loans apply to future payslips: add or change them from the next payroll run.'
            : null;
    }

    public function addDeduction(SaveEmployeeDeduction $saveDeduction): void
    {
        $this->authorize(Ability::ManagePayroll->value);
        $this->ensureLoansEditable();

        $validated = $this->validate([
            'deductionLabel' => ['required', 'string', 'max:255'],
            'deductionType' => ['required', Rule::enum(DeductionType::class)],
            'deductionInstallment' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'deductionTotal' => [Rule::requiredIf(fn () => DeductionType::tryFrom($this->deductionType)?->tracksBalance() ?? false), 'nullable', 'numeric', 'gte:deductionInstallment', 'max:9999999'],
            'deductionBasis' => ['required', Rule::in(array_map(fn (DeductionAuthorizationBasis $basis) => $basis->value, DeductionAuthorizationBasis::choices()))],
        ], [], ['deductionInstallment' => 'installment', 'deductionTotal' => 'total amount', 'deductionBasis' => 'authorization']);

        $deduction = $saveDeduction->handle(
            $this->selectedEmployeeOrFail(),
            $validated['deductionLabel'],
            DeductionType::from($validated['deductionType']),
            (float) $validated['deductionInstallment'],
            $validated['deductionTotal'] !== null && $validated['deductionTotal'] !== '' ? (float) $validated['deductionTotal'] : null,
            $this->currentUser(),
            DeductionAuthorizationBasis::from($validated['deductionBasis']),
        );

        $this->resetDeductionForm();
        $this->clearComputed();
        $this->dispatch('notify', message: $deduction->awaiting_authorization ? 'Deduction added. They were asked to authorize it; nothing is deducted until they do.' : 'Deduction added.');
    }

    public function toggleDeduction(int $deductionId, ToggleEmployeeDeduction $toggle): void
    {
        $this->authorize(Ability::ManagePayroll->value);
        $this->ensureLoansEditable();

        $toggle->handle($this->deductionOfSelected($deductionId), $this->currentUser());
        $this->clearComputed();
    }

    public function deleteDeduction(int $deductionId, DeleteEmployeeDeduction $delete): void
    {
        $this->authorize(Ability::ManagePayroll->value);
        $this->ensureLoansEditable();

        $delete->handle($this->deductionOfSelected($deductionId), $this->currentUser());
        $this->clearComputed();
        $this->dispatch('notify', message: 'Deduction removed.');
    }

    public function exportCsv(): StreamedResponse
    {
        $this->authorize(Ability::ManagePayroll->value);

        $run = $this->selectedRunOrFail();
        $resolver = app(ResolvePayslips::class);

        return response()->streamDownload(function () use ($run, $resolver) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            // Employee AND employer shares, so the file also feeds the SSS, PhilHealth and Pag-IBIG remittances.
            fputcsv($out, ['Employee No.', 'Name', 'Days', 'Gross', 'Deductions', 'Net', 'Status',
                'SSS MSC', 'SSS (EE)', 'SSS (ER)', 'SSS EC (ER)', 'PhilHealth (EE)', 'PhilHealth (ER)', 'Pag-IBIG (EE)', 'Pag-IBIG (ER)', 'Withholding tax']);

            $money = fn (float $amount): string => number_format($amount, 2, '.', '');

            // Chunked: memory stays flat at any headcount.
            User::query()->onPayroll()->with('employeeProfile')->orderBy('name')->chunkById(200, function (Collection $users) use ($out, $run, $resolver, $money) {
                foreach ($resolver->handle($run, $users) as $userId => $payslip) {
                    $user = $users->find($userId);
                    $c = $payslip->contributions;
                    fputcsv($out, [
                        $user?->employeeProfile?->employee_number, $user?->name, $payslip->daysWorked,
                        $money($payslip->grossPay()), $money($payslip->totalDeductions()), $money($payslip->netPay()),
                        $payslip->isGenerated ? 'Generated' : 'Estimate',
                        $c === [] ? '' : $money($c['msc']),
                        $money($payslip->sss), $c === [] ? '' : $money($c['sss']['employer']), $c === [] ? '' : $money($c['sss']['ec']),
                        $money($payslip->philhealth), $c === [] ? '' : $money($c['philhealth']['employer']),
                        $money($payslip->pagibig), $c === [] ? '' : $money($c['pagibig']['employer']),
                        $money($payslip->withholdingTax),
                    ]);
                }
            });

            fclose($out);
        }, "payroll-{$run->start_date->toDateString()}.csv", ['Content-Type' => 'text/csv']);
    }

    // ---- Read side --------------------------------------------------------

    /**
     * @return Collection<int, PayrollRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return PayrollOverviewQuery::recentRuns();
    }

    #[Computed]
    public function selectedRun(): ?PayrollRun
    {
        return $this->selectedRunId !== null ? PayrollRun::query()->find($this->selectedRunId) : null;
    }

    /**
     * Latest run's register and deductions mix, from frozen records only (shared with the dashboard).
     *
     * @return PayrollOverview
     */
    #[Computed]
    public function stats(): array
    {
        return app(PayrollOverviewQuery::class)->handle($this->runs);
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function employees(): LengthAwarePaginator
    {
        return User::query()->onPayroll()->with('employeeProfile')->orderBy('name')->paginate(20);
    }

    /**
     * Payslips for the employees on the current page only.
     *
     * @return array<int, PayslipBreakdown>
     */
    #[Computed]
    public function pagePayslips(): array
    {
        $run = $this->selectedRun;

        if ($run === null) {
            return [];
        }

        /** @var Collection<int, User> $users */
        $users = new Collection($this->employees->items());

        return app(ResolvePayslips::class)->handle($run, $users);
    }

    #[Computed]
    public function missingPayslips(): int
    {
        return $this->selectedRun !== null ? app(AdvancePayrollRun::class)->employeesWithoutPayslip($this->selectedRun) : 0;
    }

    #[Computed]
    public function approvalBlockedReason(): ?string
    {
        return $this->selectedRun !== null ? app(AdvancePayrollRun::class)->approvalBlockedReason($this->selectedRun) : null;
    }

    #[Computed]
    public function selectedEmployee(): ?User
    {
        return $this->selectedUserId !== null ? User::query()->with('employeeProfile')->find($this->selectedUserId) : null;
    }

    #[Computed]
    public function selectedPayslip(): ?PayslipBreakdown
    {
        return $this->selectedRun !== null && $this->selectedEmployee !== null
            ? app(ResolvePayslips::class)->forUser($this->selectedRun, $this->selectedEmployee)
            : null;
    }

    #[Computed]
    public function selectedRecord(): ?PayslipRecord
    {
        return $this->selectedRunId !== null && $this->selectedUserId !== null
            ? PayslipRecord::query()->where('payroll_run_id', $this->selectedRunId)->where('user_id', $this->selectedUserId)->first()
            : null;
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function selectedAttendance(): array
    {
        if ($this->selectedRun === null || $this->selectedEmployee === null) {
            return [];
        }

        $query = app(AttendanceHistoryQuery::class);
        $summary = $query->summarize($query->handle($this->selectedEmployee, $this->selectedRun->start_date, $this->selectedRun->end_date->min(CarbonImmutable::today())));

        return ['present' => $summary['present'], 'absent' => $summary['absent'], 'on_leave' => $summary['onLeave'], 'late' => $summary['late']];
    }

    /**
     * @return list<LateDay>
     */
    #[Computed]
    public function selectedLateDays(): array
    {
        return $this->selectedRun !== null && $this->selectedEmployee !== null
            ? app(LateDaysQuery::class)->handle($this->selectedRun, $this->selectedEmployee)
            : [];
    }

    /**
     * Active AND paused deductions, so a paused one can be resumed.
     *
     * @return Collection<int, EmployeeDeduction>
     */
    #[Computed]
    public function deductions(): Collection
    {
        return $this->selectedUserId !== null
            ? EmployeeDeduction::query()->where('user_id', $this->selectedUserId)->orderByDesc('is_active')->orderBy('id')->get()
            : new Collection;
    }

    // ---- Helpers ----------------------------------------------------------

    private function selectedRunOrFail(): PayrollRun
    {
        return PayrollRun::query()->findOrFail($this->selectedRunId);
    }

    private function selectedEmployeeOrFail(): User
    {
        return User::query()->onPayroll()->findOrFail($this->selectedUserId);
    }

    /**
     * Looked up THROUGH the open employee, so a forged id from someone else's
     * deduction is simply not found.
     */
    private function deductionOfSelected(int $deductionId): EmployeeDeduction
    {
        return EmployeeDeduction::query()->where('user_id', $this->selectedUserId)->findOrFail($deductionId);
    }

    private function resetDeductionForm(): void
    {
        $this->reset(['deductionLabel', 'deductionInstallment', 'deductionTotal', 'deductionBasis']);
        $this->deductionType = DeductionType::Loan->value;
        $this->resetErrorBag();
    }

    /**
     * The same rule the view shows, enforced on the server: a hidden button is never the only protection.
     */
    private function ensureLoansEditable(): void
    {
        if (($reason = $this->loansLockedReason) !== null) {
            throw ValidationException::withMessages(['deductionLabel' => $reason]);
        }
    }

    private function clearComputed(): void
    {
        unset($this->runs, $this->selectedRun, $this->stats, $this->pagePayslips, $this->missingPayslips, $this->approvalBlockedReason, $this->selectedPayslip, $this->selectedRecord, $this->deductions, $this->pendingAdjustments, $this->loansLockedReason, $this->noRateWarning);
    }

    public function render(): View
    {
        return view('livewire.admin.payroll', ['deductionTypes' => DeductionType::manual()]);
    }
}
