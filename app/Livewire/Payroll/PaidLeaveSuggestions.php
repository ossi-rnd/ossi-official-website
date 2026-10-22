<?php

namespace App\Livewire\Payroll;

use App\Actions\Payroll\AcceptPaidLeave;
use App\Actions\Payroll\DetectPaidLeave;
use App\Actions\Payroll\DismissPaidLeave;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\PaidLeavePayment;
use App\Models\PayrollRun;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Paid leave this cut-off" on an open payroll run: the leave the law pays
 * (DetectPaidLeave), for Accounting to accept (fewer days if a rest day falls in
 * it; the SSS benefit amount for maternity) or dismiss with a reason. Beside
 * "Corrections from earlier cut-offs"; tells the page to re-read through `adjustments-changed`.
 *
 * @property-read Collection<int, PaidLeavePayment> $payments
 */
class PaidLeaveSuggestions extends Component
{
    use InteractsWithUser;

    #[Locked]
    public int $runId;

    /** @var array<int, string> days to pay, per suggestion */
    public array $days = [];

    /** @var array<int, string> the SSS maternity benefit, per suggestion */
    public array $sssAmount = [];

    public ?int $dismissingId = null;

    public string $dismissReason = '';

    public function mount(int $runId, DetectPaidLeave $detect): void
    {
        $this->authorize(Ability::ManagePayroll->value);
        $this->runId = $runId;
        $detect->handle(PayrollRun::query()->findOrFail($runId));
    }

    /**
     * Undecided first, then what was decided on this run.
     *
     * @return Collection<int, PaidLeavePayment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return PaidLeavePayment::query()->where('payroll_run_id', $this->runId)->with(['user:id,name', 'leaveRequest'])
            ->orderByRaw("case when status = 'suggested' then 0 else 1 end")->orderBy('id')->get();
    }

    public function accept(int $id, AcceptPaidLeave $accept): void
    {
        $payment = $this->find($id);
        $days = isset($this->days[$id]) && $this->days[$id] !== '' ? (int) $this->days[$id] : null;
        $sss = isset($this->sssAmount[$id]) && $this->sssAmount[$id] !== '' ? (float) $this->sssAmount[$id] : null;

        $accepted = $accept->handle($payment, $this->currentUser(), $days, $sss);
        $this->changed("Added to {$accepted->user->name}'s payslip.");
    }

    public function startDismiss(int $id): void
    {
        $this->dismissingId = $this->find($id)->id;
        $this->dismissReason = '';
        $this->resetErrorBag();
    }

    public function dismiss(DismissPaidLeave $dismiss): void
    {
        $dismiss->handle($this->find((int) $this->dismissingId), $this->currentUser(), $this->dismissReason);
        $this->reset(['dismissingId', 'dismissReason']);
        $this->changed('Dismissed. It won\'t be suggested again on this run.');
    }

    public function render(): View
    {
        return view('livewire.payroll.paid-leave-suggestions');
    }

    /** Looked up THROUGH this run, so a forged id is simply not found. */
    private function find(int $id): PaidLeavePayment
    {
        return PaidLeavePayment::query()->where('payroll_run_id', $this->runId)->findOrFail($id);
    }

    private function changed(string $message): void
    {
        unset($this->payments);
        $this->resetErrorBag();
        $this->dispatch('adjustments-changed');
        $this->dispatch('notify', message: $message);
    }
}
