<?php

namespace App\Livewire\Reimbursements;

use App\Actions\Payroll\CancelReimbursement;
use App\Actions\Payroll\FileReimbursement;
use App\Enums\ReimbursementCategory;
use App\Livewire\Concerns\ChoosesAcknowledgementLanguage;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\AwayLog;
use App\Models\Reimbursement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Office staff's reimbursements (roadmap 25): file one with the receipt, see
 * where each stands, cancel one still waiting. Paid back on the next payslip.
 *
 * @property-read Collection<int, Reimbursement> $reimbursements
 * @property-read Collection<int, AwayLog> $trips
 */
#[Title('My Reimbursements')]
class MyReimbursements extends Component
{
    use ChoosesAcknowledgementLanguage, InteractsWithUser, WithFileUploads;

    public bool $filing = false;

    public string $category = 'transportation';

    public string $spentOn = '';

    public string $amount = '';

    public string $purpose = '';

    public string $awayLogId = '';

    /** @var UploadedFile|null */
    public $receipt = null;

    /** "I certify these are true": required; the lines are saved with the claim. */
    public bool $acknowledges = false;

    /** Which deployment, for a "Deployment" cost. */
    public string $deploymentFor = '';

    public function mount(): void
    {
        // Office staff file their own; a guard sees the deployment costs Operations claimed for them.
        abort_unless(Reimbursement::canSeeOwn($this->currentUser()), 403);
    }

    /** Office staff file their own; a guard only sees what Operations filed for them. */
    public function canFile(): bool
    {
        return Reimbursement::canFile($this->currentUser());
    }

    /**
     * @return Collection<int, Reimbursement>
     */
    #[Computed]
    public function reimbursements(): Collection
    {
        return Reimbursement::query()->where('user_id', $this->currentUser()->id)->with(['decider:id,name', 'filer:id,name', 'paidInRun', 'awayLog'])->latest('id')->limit(50)->get();
    }

    /**
     * Their Office Away trips in the receipt window, to say which one it came from.
     *
     * @return Collection<int, AwayLog>
     */
    #[Computed]
    public function trips(): Collection
    {
        return AwayLog::query()->where('user_id', $this->currentUser()->id)
            ->where('left_at', '>=', CarbonImmutable::today()->subDays(Reimbursement::fileWithinDays()))
            ->latest('left_at')->limit(20)->get();
    }

    public function start(): void
    {
        abort_unless($this->canFile(), 403);
        $this->reset(['purpose', 'amount', 'awayLogId', 'receipt', 'acknowledges', 'deploymentFor']);
        $this->category = ReimbursementCategory::Transportation->value;
        $this->spentOn = CarbonImmutable::today()->toDateString();
        $this->filing = true;
        $this->resetErrorBag();
    }

    public function file(FileReimbursement $file): void
    {
        $validated = $this->validate([
            'category' => ['required', Rule::enum(ReimbursementCategory::class)],
            'spentOn' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:1', 'max:'.Reimbursement::maxAmount()],
            'purpose' => ['required', 'string', 'min:10', 'max:1000'],
            'awayLogId' => ['nullable', 'integer'],
            'deploymentFor' => [Rule::requiredIf($this->category === ReimbursementCategory::Deployment->value), 'nullable', 'string', 'max:255'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'acknowledges' => ['accepted'],
        ], [
            'acknowledges.accepted' => 'Read the points above and tick the box to send.',
            'receipt.required' => 'Attach the receipt (a photo or a PDF).',
            'receipt.mimes' => 'Attach a photo (JPG, PNG) or a PDF.',
            'purpose.required' => 'Say what it was for, and for whom.',
            'purpose.min' => 'Say what it was for, and for whom.',
        ]);

        if (! $this->receipt instanceof UploadedFile) {
            throw ValidationException::withMessages(['receipt' => 'Attach the receipt (a photo or a PDF).']);
        }

        $file->handle(
            $this->currentUser(),
            ReimbursementCategory::from($validated['category']),
            CarbonImmutable::parse($validated['spentOn']),
            (float) $validated['amount'],
            $validated['purpose'],
            $this->receipt,
            $this->awayLogId !== '' ? (int) $this->awayLogId : null,
            acknowledged: true,
            locale: $this->ackLocale,
            deploymentFor: $validated['deploymentFor'] ?? null,
        );

        $this->reset(['filing', 'purpose', 'amount', 'awayLogId', 'receipt', 'acknowledges', 'deploymentFor']);
        unset($this->reimbursements);
        $this->dispatch('notify', message: 'Sent to the President or Vice President. Once approved, it\'s added to your next payslip.');
    }

    public function cancel(int $id, CancelReimbursement $cancel): void
    {
        $cancel->handle($this->currentUser(), Reimbursement::query()->where('user_id', $this->currentUser()->id)->findOrFail($id));
        unset($this->reimbursements);
        $this->dispatch('notify', message: 'Cancelled.');
    }

    public function render(): View
    {
        return view('livewire.reimbursements.my-reimbursements', ['categories' => ReimbursementCategory::cases()]);
    }
}
