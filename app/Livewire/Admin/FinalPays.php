<?php

namespace App\Livewire\Admin;

use App\Actions\FinalPay\PrepareFinalPay;
use App\Actions\FinalPay\ReturnFinalPay;
use App\Actions\FinalPay\SignFinalPay;
use App\Actions\FinalPay\SubmitFinalPay;
use App\Actions\FinalPay\UpdateFinalPayAdjustments;
use App\Enums\Ability;
use App\Enums\FinalPayStatus;
use App\Enums\ResignationStatus;
use App\Enums\Role;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\FinalPay;
use App\Models\Resignation;
use App\Queries\Payroll\FinalPayStatementQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Final pay for people who resigned: Accounting prepares the statement, then
 * Accounting and the President or Vice President sign it (the President or Vice President
 * last, which releases it). Opens to everyone who prepares or signs.
 *
 * @property-read LengthAwarePaginator<int, Resignation> $resignations
 * @property-read Resignation|null $opened
 * @property-read list<string> $warnings
 */
#[Title('Final Pay')]
class FinalPays extends Component
{
    use InteractsWithUser, WithPagination;

    #[Url(as: 'status', except: 'open')]
    public string $statusFilter = 'open';

    /** The resignation opened. */
    #[Url(as: 'open', except: null)]
    public ?int $openedId = null;

    /** A notification's link carries the final pay instead (?pay=): mount opens its resignation. */
    #[Url(as: 'pay', except: null)]
    public ?int $payId = null;

    public string $adjustmentLabel = '';

    public string $adjustmentAmount = '';

    public string $adjustmentReason = '';

    public string $returnNote = '';

    public function mount(): void
    {
        if ($this->payId !== null) {
            $resignationId = FinalPay::query()->whereKey($this->payId)->value('resignation_id');
            $this->openedId = is_numeric($resignationId) ? (int) $resignationId : null;
            $this->payId = null;
        }
    }

    public function canPrepare(): bool
    {
        return $this->currentUser()->can(Ability::PrepareFinalPay->value);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function open(int $resignationId): void
    {
        $this->openedId = Resignation::query()->whereIn('status', [ResignationStatus::Accepted, ResignationStatus::Completed])->findOrFail($resignationId)->id;
        $this->reset(['adjustmentLabel', 'adjustmentAmount', 'adjustmentReason', 'returnNote']);
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->reset(['openedId', 'adjustmentLabel', 'adjustmentAmount', 'adjustmentReason', 'returnNote']);
    }

    public function prepare(PrepareFinalPay $prepare): void
    {
        $prepare->handle($this->openedOrFail(), $this->currentUser());
        $this->refreshLists('Statement computed.');
    }

    public function addAdjustment(UpdateFinalPayAdjustments $update): void
    {
        $validated = $this->validate([
            'adjustmentLabel' => ['required', 'string', 'max:120'],
            'adjustmentAmount' => ['required', 'numeric', 'between:-999999,999999'],
            'adjustmentReason' => ['required', 'string', 'max:255'],
        ], [
            'adjustmentLabel.required' => 'Name it.',
            'adjustmentAmount.required' => 'Enter an amount: − takes off.',
            'adjustmentReason.required' => 'Say why: it shows on the statement.',
        ], ['adjustmentLabel' => 'label', 'adjustmentAmount' => 'amount', 'adjustmentReason' => 'reason']);

        $update->handle($this->finalPayOrFail(), $this->currentUser(), $validated['adjustmentLabel'], (float) $validated['adjustmentAmount'], $validated['adjustmentReason']);
        $this->reset(['adjustmentLabel', 'adjustmentAmount', 'adjustmentReason']);
        $this->refreshLists('Adjustment added.');
    }

    public function removeAdjustment(int $index, UpdateFinalPayAdjustments $update): void
    {
        $update->handle($this->finalPayOrFail(), $this->currentUser(), removeIndex: $index);
        $this->refreshLists('Adjustment removed.');
    }

    public function submit(SubmitFinalPay $submit): void
    {
        $submit->handle($this->finalPayOrFail(), $this->currentUser());
        $this->refreshLists('Sent for signatures.');
    }

    public function sign(SignFinalPay $sign): void
    {
        $finalPay = $sign->handle($this->finalPayOrFail(), $this->currentUser());
        $this->refreshLists($finalPay->status === FinalPayStatus::Released ? 'Signed and released. The employee was told.' : 'Signed.');
    }

    public function sendBack(ReturnFinalPay $return): void
    {
        $return->handle($this->finalPayOrFail(), $this->currentUser(), $this->returnNote);
        $this->reset('returnNote');
        $this->refreshLists('Sent back to Accounting.');
    }

    /**
     * Accepted (or completed) resignations and their final pay, the ones needing
     * action first: "open" = not released yet.
     *
     * @return LengthAwarePaginator<int, Resignation>
     */
    #[Computed]
    public function resignations(): LengthAwarePaginator
    {
        return Resignation::query()
            ->whereIn('status', [ResignationStatus::Accepted, ResignationStatus::Completed])
            ->with(['user.employeeProfile', 'finalPay'])
            ->when($this->statusFilter === 'open', fn ($query) => $query->whereDoesntHave('finalPay', fn ($pay) => $pay->where('status', FinalPayStatus::Released)))
            ->when($this->statusFilter === 'released', fn ($query) => $query->whereHas('finalPay', fn ($pay) => $pay->where('status', FinalPayStatus::Released)))
            ->orderByRaw('coalesce(last_day, requested_last_day)')
            ->paginate(20);
    }

    #[Computed]
    public function opened(): ?Resignation
    {
        return $this->openedId !== null
            ? Resignation::query()->with(['user.employeeProfile', 'finalPay.preparer'])->find($this->openedId)
            : null;
    }

    /**
     * What needs a look before submitting (a draft): e.g. days worked without a payslip.
     *
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        $opened = $this->opened;

        return $opened?->finalPay?->status === FinalPayStatus::Draft
            ? app(FinalPayStatementQuery::class)->handle($opened, $opened->finalPay->adjustments ?? [])['warnings']
            : [];
    }

    /** The role this person would sign as now, if any. */
    public function signAs(): ?Role
    {
        return $this->opened?->finalPay?->signableRoleFor($this->currentUser());
    }

    /** One of the five may send a statement back while it's being signed. */
    public function canSendBack(): bool
    {
        $finalPay = $this->opened?->finalPay;

        return $finalPay?->status === FinalPayStatus::Signing
            && $finalPay->user_id !== $this->currentUser()->id
            && array_filter(FinalPay::SIGNERS, fn (Role $role) => FinalPay::holdsStep($this->currentUser(), $role)) !== [];
    }

    public function render(): View
    {
        return view('livewire.admin.final-pays', ['signers' => FinalPay::SIGNERS]);
    }

    private function openedOrFail(): Resignation
    {
        return $this->opened ?? abort(404);
    }

    private function finalPayOrFail(): FinalPay
    {
        return $this->openedOrFail()->finalPay ?? abort(404);
    }

    private function refreshLists(string $message): void
    {
        unset($this->opened, $this->resignations, $this->warnings);
        $this->dispatch('notify', message: $message);
    }
}
