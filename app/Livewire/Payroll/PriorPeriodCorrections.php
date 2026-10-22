<?php

namespace App\Livewire\Payroll;

use App\Actions\Payroll\AcceptPriorPeriodCorrection;
use App\Actions\Payroll\DetectPriorPeriodCorrections;
use App\Actions\Payroll\DismissPriorPeriodCorrection;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\PayrollRun;
use App\Models\PriorPeriodCorrection;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Corrections from earlier cut-offs" on an open payroll run: pay that changed
 * after a payslip was frozen, for Accounting to accept (→ a Correction
 * adjustment on this payslip) or dismiss with a reason. Its own component so the
 * Payroll page doesn't grow; it tells the page to re-read through `adjustments-changed`.
 *
 * @property-read PayrollRun $run
 * @property-read Collection<int, PriorPeriodCorrection> $corrections
 */
class PriorPeriodCorrections extends Component
{
    use InteractsWithUser;

    #[Locked]
    public int $runId;

    public ?int $dismissingId = null;

    public string $dismissReason = '';

    public function mount(int $runId): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $this->runId = $runId;
    }

    #[Computed]
    public function run(): PayrollRun
    {
        return PayrollRun::query()->findOrFail($this->runId);
    }

    /**
     * Undecided first, then what was decided on this run (the record stays visible).
     *
     * @return Collection<int, PriorPeriodCorrection>
     */
    #[Computed]
    public function corrections(): Collection
    {
        return PriorPeriodCorrection::query()
            ->where('payroll_run_id', $this->runId)
            ->with(['user:id,name', 'fromRun', 'adjustment'])
            ->orderByRaw("case when status = 'suggested' then 0 else 1 end")
            ->orderBy('id')
            ->get();
    }

    public function check(DetectPriorPeriodCorrections $detect): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $open = $detect->handle($this->run);
        $this->changed($open > 0 ? "{$open} ".str('correction')->plural($open).' to decide.' : 'No pay changed on earlier cut-offs.');
    }

    public function accept(int $id, AcceptPriorPeriodCorrection $accept): void
    {
        $correction = $accept->handle($this->find($id), $this->currentUser());
        $this->changed("Added to {$correction->user->name}'s payslip.");
    }

    public function acceptAll(AcceptPriorPeriodCorrection $accept): void
    {
        $accepted = 0;

        foreach ($this->corrections->where('status', PriorPeriodCorrection::SUGGESTED) as $correction) {
            try {
                $accept->handle($correction, $this->currentUser());
                $accepted++;
            } catch (ValidationException) {
                // Left for a look (e.g. no attendance on this run): it stays in the list.
            }
        }

        $this->changed("Accepted {$accepted} ".str('correction')->plural($accepted).'.');
    }

    public function startDismiss(int $id): void
    {
        $this->dismissingId = $this->find($id)->id;
        $this->dismissReason = '';
        $this->resetErrorBag();
    }

    public function dismiss(DismissPriorPeriodCorrection $dismiss): void
    {
        $dismiss->handle($this->find((int) $this->dismissingId), $this->currentUser(), $this->dismissReason);
        $this->reset(['dismissingId', 'dismissReason']);
        $this->changed('Dismissed. It won\'t be suggested again.');
    }

    public function render(): View
    {
        return view('livewire.payroll.prior-period-corrections');
    }

    /**
     * Looked up THROUGH this run, so a forged id is simply not found.
     */
    private function find(int $id): PriorPeriodCorrection
    {
        return PriorPeriodCorrection::query()->where('payroll_run_id', $this->runId)->findOrFail($id);
    }

    private function changed(string $message): void
    {
        unset($this->corrections);
        $this->resetErrorBag();
        $this->dispatch('adjustments-changed');
        $this->dispatch('notify', message: $message);
    }
}
