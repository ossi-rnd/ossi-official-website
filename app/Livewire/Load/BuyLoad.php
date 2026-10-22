<?php

namespace App\Livewire\Load;

use App\Actions\Load\CancelLoadRequest;
use App\Actions\Load\SubmitLoadRequest;
use App\Enums\DeductionType;
use App\Livewire\Concerns\ChoosesAcknowledgementLanguage;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeDeduction;
use App\Models\LoadRequest;
use App\Support\PhilippineFormats;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Borrow Load (mobile load on credit): pick the number and amount and send the request. An
 * Admin sends the load and approves; the amount comes off the next payslip.
 *
 * @property-read Collection<int, LoadRequest> $requests
 * @property-read float $owed
 */
#[Title('Borrow Load')]
class BuyLoad extends Component
{
    use ChoosesAcknowledgementLanguage, InteractsWithUser;

    public string $network = '';

    public string $mobileNumber = '';

    public int $amount = 0;

    /** The written authorization (Art. 113) to deduct it from their next payslip. */
    public bool $authorized = false;

    public function mount(): void
    {
        // Their number on file is the usual target; they can change it.
        $this->mobileNumber = PhilippineFormats::mobileNumber((string) $this->currentUser()->loadMissing('employeeProfile')->employeeProfile?->contact_number) ?? '';
    }

    /**
     * @return Collection<int, LoadRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        return LoadRequest::query()->where('user_id', $this->currentUser()->id)->with('deduction')->latest()->limit(20)->get();
    }

    /**
     * Approved load not yet deducted from a payslip.
     */
    #[Computed]
    public function owed(): float
    {
        return (float) EmployeeDeduction::query()
            ->where('user_id', $this->currentUser()->id)
            ->where('type', DeductionType::Load)
            ->where('is_active', true)
            ->sum('remaining_balance');
    }

    public function submit(SubmitLoadRequest $submit): void
    {
        $validated = $this->validate([
            'network' => ['required', Rule::in(array_keys((array) config('load.networks')))],
            'amount' => ['required', 'integer', Rule::in((array) config('load.amounts'))],
            'mobileNumber' => ['required', 'string', 'max:20'],
            // The Action requires it too; checked here so it shows with the other fields.
            'authorized' => ['accepted'],
        ], ['authorized.accepted' => 'Tick the box to authorize the deduction from your payslip.'], ['mobileNumber' => 'mobile number']);

        $loadRequest = $submit->handle($this->currentUser(), [
            'network' => $validated['network'],
            'mobile_number' => $validated['mobileNumber'],
            'amount' => (int) $validated['amount'],
            'authorized' => $this->authorized,
            'locale' => $this->ackLocale,
        ]);

        $this->reset(['amount', 'authorized']);
        unset($this->requests);
        $this->dispatch('notify', message: "Request sent: ₱{$loadRequest->amount} {$loadRequest->networkLabel()} to {$loadRequest->formattedNumber()}. You'll be notified when it's sent.");
    }

    public function cancel(int $id, CancelLoadRequest $cancel): void
    {
        $cancel->handle(LoadRequest::query()->where('user_id', $this->currentUser()->id)->findOrFail($id), $this->currentUser());

        unset($this->requests);
        $this->dispatch('notify', message: 'Load request cancelled.');
    }

    public function render(): View
    {
        return view('livewire.load.buy-load', [
            'networks' => (array) config('load.networks'),
            'amounts' => (array) config('load.amounts'),
        ]);
    }
}
