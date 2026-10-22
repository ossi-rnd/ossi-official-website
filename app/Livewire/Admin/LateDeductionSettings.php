<?php

namespace App\Livewire\Admin;

use App\Actions\Settings\DeleteLateDeductionRule;
use App\Actions\Settings\SaveLateDeductionRule;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\LateDeductionRule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Late Deduction Settings')]
class LateDeductionSettings extends Component
{
    use InteractsWithUser;

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $minMinutes = '';

    public string $maxMinutes = '';

    public string $deductionAmount = '';

    public function create(): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $this->reset(['editingId', 'minMinutes', 'maxMinutes', 'deductionAmount']);
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $rule = LateDeductionRule::query()->findOrFail($id);
        $this->editingId = $rule->id;
        $this->minMinutes = (string) $rule->min_minutes;
        $this->maxMinutes = (string) $rule->max_minutes;
        $this->deductionAmount = $rule->deduction_amount;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset(['formOpen', 'editingId', 'minMinutes', 'maxMinutes', 'deductionAmount']);
    }

    public function save(SaveLateDeductionRule $saveRule): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $validated = $this->validate([
            'minMinutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'maxMinutes' => ['nullable', 'integer', 'gt:minMinutes', 'max:1440'],
            'deductionAmount' => ['required', 'numeric', 'min:0', 'max:99999'],
        ], [], ['minMinutes' => 'from', 'maxMinutes' => 'up to', 'deductionAmount' => 'deduction']);

        $saveRule->handle($this->editingId !== null ? LateDeductionRule::query()->findOrFail($this->editingId) : null, [
            'min_minutes' => (int) $validated['minMinutes'],
            'max_minutes' => $validated['maxMinutes'] !== null && $validated['maxMinutes'] !== '' ? (int) $validated['maxMinutes'] : null,
            'deduction_amount' => $validated['deductionAmount'],
        ], $this->currentUser());

        $this->cancel();
        unset($this->rules);
        $this->dispatch('notify', message: 'Late deduction bracket saved.');
    }

    public function delete(int $id, DeleteLateDeductionRule $deleteRule): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $deleteRule->handle(LateDeductionRule::query()->findOrFail($id), $this->currentUser());
        unset($this->rules);
        $this->dispatch('notify', message: 'Bracket deleted.');
    }

    /**
     * @return Collection<int, LateDeductionRule>
     */
    #[Computed]
    public function rules(): Collection
    {
        return LateDeductionRule::query()->orderBy('min_minutes')->get();
    }

    public function render(): View
    {
        return view('livewire.admin.late-deduction-settings');
    }
}
