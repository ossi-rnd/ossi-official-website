<?php

namespace App\Livewire\Admin;

use App\Actions\Payroll\DecideReimbursement;
use App\Actions\Payroll\SaveReimbursementLimits;
use App\Enums\Ability;
use App\Enums\ReimbursementStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Reimbursement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Office staff's reimbursements: the President or Vice President approves or rejects them;
 * Accounting sees them all, and which payslip paid each.
 *
 * @property-read LengthAwarePaginator<int, Reimbursement> $requests
 * @property-read Reimbursement|null $opened
 */
#[Title('Reimbursements')]
class ReimbursementRequests extends Component
{
    use InteractsWithUser, WithPagination;

    /** pending | approved | all */
    #[Url(as: 'show')]
    public string $statusFilter = 'pending';

    #[Url(as: 'open')]
    public ?int $openId = null;

    public string $decisionNote = '';

    // Limits: Accounting edits them here (SaveReimbursementLimits); others see them.
    public bool $editingLimits = false;

    public string $fileWithinDays = '';

    public string $maxAmount = '';

    public string $deploymentMaxAmount = '';

    public function mount(): void
    {
        $this->authorize(Ability::ViewReimbursements->value);
    }

    /** Accounting sets the limits; the President or Vice President and others see them. */
    public function canEditLimits(): bool
    {
        return $this->currentUser()->can(Ability::ManagePayroll->value);
    }

    public function editLimits(): void
    {
        abort_unless($this->canEditLimits(), 403);
        $limits = Reimbursement::limits();
        $this->fileWithinDays = (string) $limits['file_within_days'];
        $this->maxAmount = (string) $limits['max_amount'];
        $this->deploymentMaxAmount = (string) $limits['deployment_max_amount'];
        $this->resetErrorBag();
        $this->editingLimits = true;
    }

    public function saveLimits(SaveReimbursementLimits $save): void
    {
        $validated = $this->validate([
            'fileWithinDays' => ['required', 'integer', 'min:7', 'max:365'],
            'maxAmount' => ['required', 'integer', 'min:100', 'max:1000000'],
            'deploymentMaxAmount' => ['required', 'integer', 'min:100', 'max:1000000'],
        ], [], ['fileWithinDays' => 'receipt window', 'maxAmount' => 'cap', 'deploymentMaxAmount' => 'deployment cap']);

        $save->handle($this->currentUser(), (int) $validated['fileWithinDays'], (int) $validated['maxAmount'], (int) $validated['deploymentMaxAmount']);

        $this->editingLimits = false;
        $this->dispatch('notify', message: 'Limits saved. The forms use them from now on.');
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Reimbursement>
     */
    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        return Reimbursement::query()
            ->when($this->statusFilter === 'pending', fn ($query) => $query->pending())
            ->when($this->statusFilter === 'approved', fn ($query) => $query->whereIn('status', [ReimbursementStatus::Approved, ReimbursementStatus::Paid]))
            ->with(['user.employeeProfile', 'filer:id,name', 'paidInRun'])
            ->latest('id')
            ->paginate(20);
    }

    #[Computed]
    public function opened(): ?Reimbursement
    {
        return $this->openId !== null ? Reimbursement::query()->with(['user.employeeProfile', 'decider', 'filer:id,name', 'awayLog', 'paidInRun'])->find($this->openId) : null;
    }

    public function canDecide(): bool
    {
        return $this->currentUser()->can(Ability::ApproveReimbursements->value);
    }

    public function open(int $id): void
    {
        $this->openId = Reimbursement::query()->findOrFail($id)->id;
        $this->decisionNote = '';
        unset($this->opened);
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->reset(['openId', 'decisionNote']);
    }

    public function approve(DecideReimbursement $decide): void
    {
        $this->decide($decide, true);
    }

    public function reject(DecideReimbursement $decide): void
    {
        $this->decide($decide, false);
    }

    public function render(): View
    {
        return view('livewire.admin.reimbursement-requests');
    }

    private function decide(DecideReimbursement $decide, bool $approve): void
    {
        $reimbursement = $this->opened;
        abort_if($reimbursement === null, 404);

        $decide->handle($this->currentUser(), $reimbursement, $approve, $this->decisionNote);
        $this->close();
        unset($this->requests);
        $this->dispatch('notify', message: $approve ? 'Approved. It\'s added to their next payslip.' : 'Rejected. They\'re told why.');
    }
}
