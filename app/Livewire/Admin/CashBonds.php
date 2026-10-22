<?php

namespace App\Livewire\Admin;

use App\Actions\Payroll\EnrollCashBond;
use App\Actions\Payroll\RecordCashBondEntry;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\CashBondEntry;
use App\Models\User;
use App\Queries\Payroll\CashBondBalanceQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Accounting's view of everyone's cash bond: ₱100 a cut-off from payslips,
 * plus what was paid in before the system (entered once), corrections, and
 * the refund in final pay. Starts it for guards, one or all at once.
 *
 * @property-read LengthAwarePaginator<int, User> $people
 * @property-read array<int, array{per_cutoff: float, opening: float, collected: float, adjustments: float, refunded: float, balance: float}> $balances
 * @property-read User|null $editing
 */
#[Title('Cash Bonds')]
class CashBonds extends Component
{
    use InteractsWithUser, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'who', except: 'guards')]
    public string $who = 'guards';

    public ?int $editingId = null;

    /** opening | adjustment */
    public string $kind = CashBondEntry::OPENING;

    public string $amount = '';

    public string $note = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedWho(): void
    {
        $this->resetPage();
    }

    public function enroll(int $userId, EnrollCashBond $enroll): void
    {
        $this->authorize(Ability::PrepareFinalPay->value);

        $enroll->handle(User::query()->findOrFail($userId), $this->currentUser());
        unset($this->balances);
        $this->dispatch('notify', message: 'Cash bond started: ₱100 a cut-off from the next payslip.');
    }

    public function enrollAllGuards(EnrollCashBond $enroll): void
    {
        $this->authorize(Ability::PrepareFinalPay->value);

        $started = 0;
        User::query()->onPayroll()->fieldStaff()->orderBy('id')->each(function (User $guard) use ($enroll, &$started) {
            $started += $enroll->handle($guard, $this->currentUser()) ? 1 : 0;
        });

        unset($this->balances);
        $this->dispatch('notify', message: $started === 0 ? 'Every guard is already enrolled.' : "Started {$started} cash ".str('bond')->plural($started).'.');
    }

    public function startEntry(int $userId, string $kind): void
    {
        $this->authorize(Ability::PrepareFinalPay->value);

        $this->editingId = User::query()->findOrFail($userId)->id;
        $this->kind = $kind === CashBondEntry::ADJUSTMENT ? CashBondEntry::ADJUSTMENT : CashBondEntry::OPENING;
        $this->reset(['amount', 'note']);
        $this->resetErrorBag();
    }

    public function cancelEntry(): void
    {
        $this->reset(['editingId', 'amount', 'note']);
    }

    public function saveEntry(RecordCashBondEntry $record): void
    {
        $validated = $this->validate(['amount' => ['required', 'numeric', 'between:-999999,999999'], 'note' => ['nullable', 'string', 'max:255']]);

        $record->handle($this->editingOrFail(), $this->kind, (float) $validated['amount'], $validated['note'] ?? null, $this->currentUser());

        $this->cancelEntry();
        unset($this->balances);
        $this->dispatch('notify', message: 'Saved.');
    }

    /**
     * People on payroll (guards by default), with or without a cash bond yet.
     *
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function people(): LengthAwarePaginator
    {
        return User::query()
            ->onPayroll()
            ->with('employeeProfile')
            ->when($this->who === 'guards', fn ($query) => $query->fieldStaff())
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * The balances of the people on this page only.
     *
     * @return array<int, array{per_cutoff: float, opening: float, collected: float, adjustments: float, refunded: float, balance: float}>
     */
    #[Computed]
    public function balances(): array
    {
        return app(CashBondBalanceQuery::class)->handle(array_values(array_map(fn (User $user) => $user->id, $this->people->items())));
    }

    #[Computed]
    public function editing(): ?User
    {
        return $this->editingId !== null ? User::query()->find($this->editingId) : null;
    }

    public function render(): View
    {
        return view('livewire.admin.cash-bonds');
    }

    private function editingOrFail(): User
    {
        return $this->editing ?? abort(404);
    }
}
