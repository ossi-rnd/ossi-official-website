<?php

namespace App\Livewire\Payroll;

use App\Actions\Payroll\AnswerDeductionAuthorization;
use App\Livewire\Concerns\ChoosesAcknowledgementLanguage;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeDeduction;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * On My Payslips: deductions Accounting asked the employee to authorize (Art. 113).
 * Nothing is taken from pay until they tick "I authorize"; they can also say they
 * don't agree. Shows nothing when there's nothing to answer.
 *
 * @property-read Collection<int, EmployeeDeduction> $waiting
 */
class DeductionAuthorizations extends Component
{
    use ChoosesAcknowledgementLanguage, InteractsWithUser;

    /**
     * @return Collection<int, EmployeeDeduction>
     */
    #[Computed]
    public function waiting(): Collection
    {
        return EmployeeDeduction::query()->where('user_id', $this->currentUser()->id)->awaitingAuthorization()->orderBy('id')->get();
    }

    public function authorizeDeduction(int $id, AnswerDeductionAuthorization $answer): void
    {
        $answer->handle($this->currentUser(), $this->ownOrFail($id), true, $this->ackLocale);
        unset($this->waiting);
        $this->dispatch('notify', message: 'Authorized. It\'s deducted from your payslips from now on.');
    }

    public function decline(int $id, AnswerDeductionAuthorization $answer): void
    {
        $answer->handle($this->currentUser(), $this->ownOrFail($id), false);
        unset($this->waiting);
        $this->dispatch('notify', message: 'Accounting was told you don\'t agree. Nothing is deducted.');
    }

    public function render(): View
    {
        return view('livewire.payroll.deduction-authorizations');
    }

    private function ownOrFail(int $id): EmployeeDeduction
    {
        return EmployeeDeduction::query()->where('user_id', $this->currentUser()->id)->findOrFail($id);
    }
}
