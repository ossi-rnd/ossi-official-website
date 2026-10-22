<?php

namespace App\Livewire\Admin;

use App\Actions\Payroll\AnswerPayrollConcern;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\PayrollConcern;
use App\Models\PayrollRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Accounting's list of what employees reported on their payslips. Open one, read
 * it (with the attachment and a link to that payslip on Payroll), then answer:
 * pay it on an open payslip, explain why it's right, or mark it not valid.
 *
 * @property-read LengthAwarePaginator<int, PayrollConcern> $concerns
 * @property-read PayrollConcern|null $opened
 * @property-read Collection<int, PayrollRun> $payableRuns
 */
#[Title('Payroll Concerns')]
class PayrollConcerns extends Component
{
    use InteractsWithUser, WithPagination;

    /** open | all */
    #[Url(as: 'show')]
    public string $statusFilter = 'open';

    #[Url(as: 'open')]
    public ?int $openId = null;

    public string $outcome = AnswerPayrollConcern::PAY;

    public string $response = '';

    public string $payRunId = '';

    /** add | deduct */
    public string $direction = 'add';

    public string $amount = '';

    public function mount(): void
    {
        $this->authorize(Ability::ManagePayroll->value);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, PayrollConcern>
     */
    #[Computed]
    public function concerns(): LengthAwarePaginator
    {
        return PayrollConcern::query()
            ->when($this->statusFilter === 'open', fn ($query) => $query->open())
            ->with(['user.employeeProfile', 'run'])
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->latest('id')
            ->paginate(20);
    }

    #[Computed]
    public function opened(): ?PayrollConcern
    {
        return $this->openId !== null
            ? PayrollConcern::query()->with(['user.employeeProfile', 'run', 'adjustment.run', 'resolver'])->find($this->openId)
            : null;
    }

    /**
     * @return Collection<int, PayrollRun>
     */
    #[Computed]
    public function payableRuns(): Collection
    {
        return $this->opened !== null ? AnswerPayrollConcern::payableRuns($this->opened->user) : new Collection;
    }

    public function open(int $id): void
    {
        $this->openId = PayrollConcern::query()->findOrFail($id)->id;
        $this->reset(['response', 'amount', 'direction']);
        unset($this->opened, $this->payableRuns);
        $this->payRunId = (string) ($this->payableRuns->first()->id ?? '');
        $this->outcome = $this->payableRuns->isEmpty() ? AnswerPayrollConcern::EXPLAIN : AnswerPayrollConcern::PAY;
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->reset(['openId', 'response', 'amount', 'payRunId', 'direction']);
    }

    public function answer(AnswerPayrollConcern $answer): void
    {
        $this->authorize(Ability::ManagePayroll->value);
        $concern = $this->opened;
        abort_if($concern === null, 404);

        $paying = $this->outcome === AnswerPayrollConcern::PAY;
        $validated = $this->validate([
            'outcome' => ['required', Rule::in([AnswerPayrollConcern::PAY, AnswerPayrollConcern::EXPLAIN, AnswerPayrollConcern::DECLINE])],
            'response' => ['required', 'string', 'min:5', 'max:1000'],
            'payRunId' => $paying ? ['required', Rule::in($this->payableRuns->pluck('id')->map(fn (int $id) => (string) $id)->all())] : [],
            'direction' => $paying ? ['required', Rule::in(['add', 'deduct'])] : [],
            'amount' => $paying ? ['required', 'numeric', 'min:0.01', 'max:999999'] : [],
        ], [
            'response.required' => 'Write the reply the employee will read.',
            'payRunId.required' => 'Choose the payslip it goes on.',
            'amount.required' => 'Enter the amount.',
        ]);

        $answer->handle(
            $concern,
            $this->currentUser(),
            $validated['outcome'],
            $validated['response'],
            $paying ? PayrollRun::query()->findOrFail((int) $validated['payRunId']) : null,
            $paying ? (float) $validated['amount'] * ($validated['direction'] === 'deduct' ? -1 : 1) : null,
        );

        $this->close();
        unset($this->concerns);
        $this->dispatch('notify', message: 'Answered. The employee is told by bell and email.');
    }

    public function render(): View
    {
        return view('livewire.admin.payroll-concerns');
    }
}
