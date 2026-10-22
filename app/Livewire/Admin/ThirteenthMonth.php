<?php

namespace App\Livewire\Admin;

use App\Actions\Payroll\AdvanceThirteenthMonthRun;
use App\Actions\Payroll\CreateThirteenthMonthRun;
use App\Actions\Payroll\DeleteThirteenthMonthRun;
use App\Actions\Payroll\RecomputeThirteenthMonthRun;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\PayrollRun;
use App\Models\ThirteenthMonthPayout;
use App\Models\ThirteenthMonthRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 13th month pay (PD 851): Accounting computes it from the year's generated
 * payslips, reviews each employee's amount, approves (employees see it on
 * My Payslips) and marks it paid. A later run for the same year pays the balance.
 *
 * @property-read Collection<int, ThirteenthMonthRun> $runs
 * @property-read ThirteenthMonthRun|null $selectedRun
 * @property-read Collection<int, ThirteenthMonthPayout> $payouts
 */
#[Title('13th Month Pay')]
class ThirteenthMonth extends Component
{
    use InteractsWithUser;

    /** Over this, the excess of 13th month and other benefits is taxable (RA 10963). */
    public const EXEMPT_CEILING = 90000;

    #[Url(as: 'run')]
    public ?int $runId = null;

    public bool $creating = false;

    public string $year = '';

    public string $coversUntil = '';

    public string $payDate = '';

    public function startCreate(): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $year = today()->year;
        $lastGenerated = PayrollRun::query()->whereHas('payslipRecords')->whereYear('end_date', $year)->max('end_date');

        $this->year = (string) $year;
        $this->coversUntil = $lastGenerated !== null ? CarbonImmutable::parse((string) $lastGenerated)->toDateString() : today()->toDateString();
        $this->payDate = "{$year}-12-20";   // before the December 24 deadline
        $this->resetErrorBag();
        $this->creating = true;
    }

    public function createRun(CreateThirteenthMonthRun $create): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $this->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:'.(today()->year + 1)],
            'coversUntil' => ['required', 'date'],
            'payDate' => ['required', 'date'],
        ]);

        $run = $create->handle((int) $this->year, CarbonImmutable::parse($this->coversUntil), CarbonImmutable::parse($this->payDate), $this->currentUser());

        $this->creating = false;
        $this->runId = $run->id;
        $this->refreshComputed();
        $this->dispatch('notify', message: "Computed {$run->label()} for {$run->payouts()->count()} employee(s). Review, then approve.");
    }

    public function viewRun(int $runId): void
    {
        $this->runId = $runId;
        $this->resetErrorBag();
        $this->refreshComputed();
    }

    public function backToList(): void
    {
        $this->runId = null;
        $this->refreshComputed();
    }

    public function recompute(RecomputeThirteenthMonthRun $recompute): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $recompute->handle($this->selectedRunOrFail(), $this->currentUser());
        $this->refreshComputed();
        $this->dispatch('notify', message: 'Recomputed from the latest payslips.');
    }

    public function advance(AdvanceThirteenthMonthRun $advance): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        try {
            $run = $advance->handle($this->selectedRunOrFail(), $this->currentUser());
        } finally {
            $this->refreshComputed();
        }

        $this->dispatch('notify', message: "13th month {$run->status->label()}.");
    }

    public function deleteRun(DeleteThirteenthMonthRun $delete): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $delete->handle($this->selectedRunOrFail(), $this->currentUser());
        $this->runId = null;
        $this->refreshComputed();
        $this->dispatch('notify', message: 'Draft deleted.');
    }

    public function exportCsv(): StreamedResponse
    {
        $this->authorize(Ability::ManagePayroll->value);

        $run = $this->selectedRunOrFail();
        $payouts = $this->payouts;

        return response()->streamDownload(function () use ($payouts) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['Employee No.', 'Name', 'Days', 'Basic pay', 'Late deductions', 'Earned (÷12)', 'Earlier paid', 'Amount', 'Status']);
            $money = fn (string $amount): string => number_format((float) $amount, 2, '.', '');

            foreach ($payouts as $payout) {
                fputcsv($out, [
                    $payout->user->employeeProfile?->employee_number, $payout->user->name, $payout->days_worked,
                    $money($payout->basic_pay), $money($payout->late_deductions), $money($payout->earned), $money($payout->earlier_paid), $money($payout->amount),
                    $payout->paid_at !== null ? 'Paid' : $payout->run->status->label(),
                ]);
            }

            fclose($out);
        }, "13th-month-{$run->year}-{$run->covers_until->toDateString()}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * @return Collection<int, ThirteenthMonthRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return ThirteenthMonthRun::query()
            ->withSum('payouts', 'amount')
            ->withCount('payouts')
            ->orderByDesc('year')
            ->orderByDesc('covers_until')
            ->get();
    }

    #[Computed]
    public function selectedRun(): ?ThirteenthMonthRun
    {
        return $this->runId !== null ? ThirteenthMonthRun::query()->find($this->runId) : null;
    }

    /**
     * @return Collection<int, ThirteenthMonthPayout>
     */
    #[Computed]
    public function payouts(): Collection
    {
        if ($this->selectedRun === null) {
            return new Collection;
        }

        return $this->selectedRun->payouts()
            ->with(['user.employeeProfile', 'run'])
            ->get()
            ->sortBy(fn (ThirteenthMonthPayout $payout) => $payout->user->name)
            ->values();
    }

    public function render(): View
    {
        return view('livewire.admin.thirteenth-month');
    }

    private function selectedRunOrFail(): ThirteenthMonthRun
    {
        return ThirteenthMonthRun::query()->findOrFail($this->runId);
    }

    private function refreshComputed(): void
    {
        unset($this->runs, $this->selectedRun, $this->payouts);
    }
}
