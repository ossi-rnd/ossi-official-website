<?php

namespace App\Livewire\Finance;

use App\Actions\Finance\DecideReplenishment;
use App\Actions\Finance\DeletePettyCashExpense;
use App\Actions\Finance\RecordPettyCashExpense;
use App\Actions\Finance\RequestReplenishment;
use App\Actions\Finance\SavePettyCashFund;
use App\Enums\Ability;
use App\Enums\ReplenishmentStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Office;
use App\Models\PettyCashExpense;
use App\Models\PettyCashFund;
use App\Models\PettyCashReplenishment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Petty cash, the imprest way. One page for everyone involved:
 *
 *   custodian    their fund: log each expense with the receipt, then ask to replenish
 *   Accounting   every fund: set them up, and release (or return) replenishments
 *   leadership   every fund, read-only
 *
 * @property-read Collection<int, PettyCashFund> $funds
 * @property-read PettyCashFund|null $fund
 * @property-read Collection<int, PettyCashExpense> $expenses
 * @property-read Collection<int, PettyCashReplenishment> $requests
 * @property-read Collection<int, PettyCashReplenishment> $waiting
 * @property-read Collection<int, User> $people
 * @property-read Collection<int, Office> $offices
 */
#[Title('Petty Cash')]
class PettyCash extends Component
{
    use InteractsWithUser, WithFileUploads;

    #[Url(as: 'fund', except: null)]
    public ?int $fundId = null;

    public bool $fundFormOpen = false;

    public ?int $editingFundId = null;

    public string $fundName = '';

    public string $fundOfficeId = '';

    public string $custodianId = '';

    public string $fundAmount = '';

    public bool $fundActive = true;

    public bool $expenseFormOpen = false;

    public string $spentOn = '';

    public string $expenseAmount = '';

    public string $paidTo = '';

    public string $expensePurpose = '';

    /** @var UploadedFile|null */
    public $receipt = null;

    public ?int $returningId = null;

    public string $decisionNote = '';

    public function mount(): void
    {
        abort_unless(PettyCashFund::canOpenPage($this->currentUser()), 403);
    }

    public function canManage(): bool
    {
        return $this->currentUser()->can(Ability::ManagePettyCash->value);
    }

    /** The custodian of the fund on screen: they log its expenses and ask to replenish. */
    public function keepsFund(): bool
    {
        return $this->fund !== null && $this->fund->is_active && $this->fund->custodian_id === $this->currentUser()->id;
    }

    public function openFund(int $id): void
    {
        $this->fundId = $id;
        unset($this->fund, $this->expenses, $this->requests);
    }

    public function createFund(): void
    {
        $this->authorize(Ability::ManagePettyCash->value);

        $this->reset(['editingFundId', 'fundName', 'fundOfficeId', 'custodianId', 'fundAmount']);
        $this->fundActive = true;
        $this->resetErrorBag();
        $this->fundFormOpen = true;
    }

    public function editFund(int $id): void
    {
        $this->authorize(Ability::ManagePettyCash->value);

        $fund = PettyCashFund::query()->findOrFail($id);
        $this->editingFundId = $fund->id;
        $this->fundName = $fund->name;
        $this->fundOfficeId = (string) $fund->office_id;
        $this->custodianId = (string) $fund->custodian_id;
        $this->fundAmount = (string) (float) $fund->amount;
        $this->fundActive = $fund->is_active;
        $this->resetErrorBag();
        $this->fundFormOpen = true;
    }

    public function saveFund(SavePettyCashFund $save): void
    {
        $this->authorize(Ability::ManagePettyCash->value);

        $validated = $this->validate([
            'fundName' => ['required', 'string', 'max:150'],
            'fundOfficeId' => ['nullable', 'integer', 'exists:offices,id'],
            'custodianId' => ['required', 'integer'],
            'fundAmount' => ['required', 'numeric', 'min:100', 'max:1000000'],
            'fundActive' => ['boolean'],
        ], attributes: ['fundName' => 'name', 'fundOfficeId' => 'office', 'custodianId' => 'custodian', 'fundAmount' => 'amount']);

        $fund = $save->handle($this->editingFundId !== null ? PettyCashFund::query()->findOrFail($this->editingFundId) : null, [
            'name' => trim($validated['fundName']),
            'office_id' => filled($validated['fundOfficeId']) ? (int) $validated['fundOfficeId'] : null,
            'custodian_id' => (int) $validated['custodianId'],
            'amount' => (float) $validated['fundAmount'],
            'is_active' => (bool) $validated['fundActive'],
        ], $this->currentUser());

        $this->fundFormOpen = false;
        $this->fundId = $fund->id;
        unset($this->funds, $this->fund);
        $this->dispatch('notify', message: 'Fund saved.');
    }

    public function startExpense(): void
    {
        abort_unless($this->keepsFund(), 403);

        $this->reset(['expenseAmount', 'paidTo', 'expensePurpose', 'receipt']);
        $this->spentOn = CarbonImmutable::today()->toDateString();
        $this->resetErrorBag();
        $this->expenseFormOpen = true;
    }

    public function saveExpense(RecordPettyCashExpense $record): void
    {
        abort_unless($this->keepsFund() && $this->fund !== null, 403);

        $this->validate([
            'spentOn' => ['required', 'date_format:Y-m-d'],
            'expenseAmount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'paidTo' => ['required', 'string', 'max:150'],
            'expensePurpose' => ['required', 'string', 'min:5', 'max:500'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], ['receipt.required' => 'Attach the receipt (a photo or a PDF).', 'receipt.mimes' => 'Attach a photo (JPG, PNG) or a PDF.'],
            ['spentOn' => 'date', 'expenseAmount' => 'amount', 'paidTo' => 'paid to', 'expensePurpose' => 'what it was for']);

        if (! $this->receipt instanceof UploadedFile) {
            throw ValidationException::withMessages(['receipt' => 'Attach the receipt (a photo or a PDF).']);
        }

        try {
            $record->handle($this->fund, $this->currentUser(), CarbonImmutable::parse($this->spentOn), (float) $this->expenseAmount, $this->paidTo, $this->expensePurpose, $this->receipt);
        } catch (ValidationException $e) {
            // The action's keys are the form's, except the amount.
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => [$key === 'amount' ? 'expenseAmount' : ($key === 'purpose' ? 'expensePurpose' : $key) => $messages])->all());
        }

        $this->expenseFormOpen = false;
        $this->reset(['receipt']);
        unset($this->fund, $this->expenses, $this->funds);
        $this->dispatch('notify', message: 'Expense recorded.');
    }

    public function deleteExpense(int $id, DeletePettyCashExpense $delete): void
    {
        $delete->handle(PettyCashExpense::query()->findOrFail($id), $this->currentUser());
        unset($this->fund, $this->expenses, $this->funds);
        $this->dispatch('notify', message: 'Expense removed.');
    }

    public function requestReplenishment(RequestReplenishment $request): void
    {
        abort_unless($this->keepsFund() && $this->fund !== null, 403);

        $request->handle($this->fund, $this->currentUser());
        unset($this->fund, $this->expenses, $this->requests, $this->funds);
        $this->dispatch('notify', message: 'Sent to Accounting.');
    }

    public function release(int $id, DecideReplenishment $decide): void
    {
        $decide->handle(PettyCashReplenishment::query()->findOrFail($id), true, null, $this->currentUser());
        unset($this->waiting, $this->fund, $this->expenses, $this->requests, $this->funds);
        $this->dispatch('notify', message: 'Released. The fund is whole again.');
    }

    public function startReturn(int $id): void
    {
        $this->authorize(Ability::ManagePettyCash->value);

        $this->returningId = PettyCashReplenishment::query()->findOrFail($id)->id;
        $this->decisionNote = '';
        $this->resetErrorBag();
    }

    public function returnRequest(DecideReplenishment $decide): void
    {
        $this->validate(['decisionNote' => ['required', 'string', 'max:500']], attributes: ['decisionNote' => 'reason']);

        $decide->handle(PettyCashReplenishment::query()->findOrFail((int) $this->returningId), false, $this->decisionNote, $this->currentUser());
        $this->reset(['returningId', 'decisionNote']);
        unset($this->waiting, $this->fund, $this->expenses, $this->requests, $this->funds);
        $this->dispatch('notify', message: 'Returned to the custodian.');
    }

    /**
     * Every fund for those who see them all; else only the one they keep.
     *
     * @return Collection<int, PettyCashFund>
     */
    #[Computed]
    public function funds(): Collection
    {
        $user = $this->currentUser();

        return PettyCashFund::query()->with(['office', 'custodian'])
            ->when(! $user->can(Ability::ViewPettyCash->value), fn ($query) => $query->where('custodian_id', $user->id))
            ->orderByDesc('is_active')->orderBy('name')
            ->get();
    }

    /** The fund on screen: the one chosen, or the only one there is. */
    #[Computed]
    public function fund(): ?PettyCashFund
    {
        $funds = $this->funds;

        return $this->fundId !== null ? $funds->firstWhere('id', $this->fundId) : ($funds->count() === 1 ? $funds->first() : null);
    }

    /**
     * The fund's expenses not yet replenished (in a request or not), newest first.
     *
     * @return Collection<int, PettyCashExpense>
     */
    #[Computed]
    public function expenses(): Collection
    {
        return $this->fund === null ? new Collection : $this->fund->outstandingExpenses()->with('replenishment')->orderByDesc('spent_on')->orderByDesc('id')->get();
    }

    /**
     * The fund's last replenishment requests.
     *
     * @return Collection<int, PettyCashReplenishment>
     */
    #[Computed]
    public function requests(): Collection
    {
        return $this->fund === null ? new Collection : $this->fund->replenishments()->with('decider')->latest('id')->limit(10)->get();
    }

    /**
     * Requests waiting for Accounting, across every fund.
     *
     * @return Collection<int, PettyCashReplenishment>
     */
    #[Computed]
    public function waiting(): Collection
    {
        return $this->canManage()
            ? PettyCashReplenishment::query()->where('status', ReplenishmentStatus::Pending)->with(['fund', 'requester'])->withCount('expenses')->oldest('id')->get()
            : new Collection;
    }

    /**
     * Who can keep a fund: anyone still working here.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return User::query()->onPayroll()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Office>
     */
    #[Computed]
    public function offices(): Collection
    {
        return Office::query()->orderBy('name')->get();
    }

    public function render(): View
    {
        return view('livewire.finance.petty-cash');
    }
}
