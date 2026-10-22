<?php

namespace App\Livewire\Payroll;

use App\Actions\Payroll\RecordPayslipView;
use App\Data\LateDay;
use App\Data\PayslipBreakdown;
use App\Enums\ThirteenthMonthStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\PayrollRun;
use App\Models\ThirteenthMonthPayout;
use App\Queries\Attendance\AttendanceHistoryQuery;
use App\Queries\Payroll\LateDaysQuery;
use App\Queries\Payroll\ResolvePayslips;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The employee's own payslips: the SAME ResolvePayslips the payroll screen
 * uses, scoped to one person, so the numbers are identical by construction.
 *
 * @property-read bool $onPayroll
 * @property-read Collection<int, PayrollRun> $runs
 * @property-read array<int, PayslipBreakdown> $payslips
 * @property-read Collection<int, ThirteenthMonthPayout> $thirteenthMonth
 * @property-read PayrollRun|null $selectedRun
 * @property-read array<string, int> $attendance
 * @property-read list<LateDay> $lateDays
 */
#[Title('My Payslips')]
class MyPayslips extends Component
{
    use InteractsWithUser;

    #[Url(as: 'run')]
    public ?int $selectedRunId = null;

    #[Computed]
    public function onPayroll(): bool
    {
        return $this->currentUser()->loadMissing('employeeProfile')->employeeProfile !== null;
    }

    /**
     * @return Collection<int, PayrollRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return PayrollRun::query()->visibleToEmployees()->orderByDesc('start_date')->limit(24)->get();
    }

    /**
     * Net pay per run for the list, one ResolvePayslips call per visible run.
     *
     * @return array<int, PayslipBreakdown>
     */
    #[Computed]
    public function payslips(): array
    {
        if (! $this->onPayroll) {
            return [];
        }

        $resolver = app(ResolvePayslips::class);

        return $this->runs->mapWithKeys(fn (PayrollRun $run) => [$run->id => $resolver->forUser($run, $this->currentUser())])->all();
    }

    /**
     * Approved and paid 13th month payouts: drafts are still being checked.
     *
     * @return Collection<int, ThirteenthMonthPayout>
     */
    #[Computed]
    public function thirteenthMonth(): Collection
    {
        return ThirteenthMonthPayout::query()
            ->where('user_id', $this->currentUser()->id)
            ->whereHas('run', fn ($run) => $run->whereIn('status', [ThirteenthMonthStatus::Approved, ThirteenthMonthStatus::Paid]))
            ->with('run')
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function selectedRun(): ?PayrollRun
    {
        return $this->selectedRunId !== null ? $this->runs->firstWhere('id', $this->selectedRunId) : null;
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function attendance(): array
    {
        $run = $this->selectedRun;

        if ($run === null) {
            return [];
        }

        $query = app(AttendanceHistoryQuery::class);
        $summary = $query->summarize($query->handle($this->currentUser(), $run->start_date, $run->end_date->min(CarbonImmutable::today())));

        return ['present' => $summary['present'], 'absent' => $summary['absent'], 'on_leave' => $summary['onLeave'], 'late' => $summary['late']];
    }

    /**
     * @return list<LateDay>
     */
    #[Computed]
    public function lateDays(): array
    {
        return $this->selectedRun !== null && $this->onPayroll
            ? app(LateDaysQuery::class)->handle($this->selectedRun, $this->currentUser())
            : [];
    }

    public function viewRun(int $runId, RecordPayslipView $recordView): void
    {
        $this->selectedRunId = $runId;

        if ($this->selectedRun !== null && $this->onPayroll) {
            $recordView->handle($this->selectedRun, $this->currentUser(), $this->currentUser());
        }
    }

    public function backToList(): void
    {
        $this->selectedRunId = null;
    }

    public function render(): View
    {
        return view('livewire.payroll.my-payslips');
    }
}
