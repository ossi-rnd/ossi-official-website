<?php

namespace App\Livewire\Admin;

use App\Actions\Settings\DeleteShift;
use App\Actions\Settings\SaveShift;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Shift;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Shifts')]
class Shifts extends Component
{
    use InteractsWithUser;

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $start_time = '09:00';

    public string $end_time = '18:00';

    public string $grace_minutes = '10';

    public bool $is_default = false;

    public function create(): void
    {
        $this->authorize(Ability::ConfigureSystem->value);

        $this->reset(['editingId', 'name', 'start_time', 'end_time', 'grace_minutes', 'is_default']);
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Ability::ConfigureSystem->value);

        $shift = Shift::query()->findOrFail($id);
        $this->editingId = $shift->id;
        $this->name = $shift->name;
        $this->start_time = substr($shift->start_time, 0, 5);
        $this->end_time = substr($shift->end_time, 0, 5);
        $this->grace_minutes = (string) $shift->grace_minutes;
        $this->is_default = $shift->is_default;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset(['formOpen', 'editingId', 'name', 'start_time', 'end_time', 'grace_minutes', 'is_default']);
    }

    public function save(SaveShift $saveShift): void
    {
        $this->authorize(Ability::ConfigureSystem->value);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('shifts', 'name')->ignore($this->editingId)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'is_default' => ['boolean'],
        ]);

        $saveShift->handle($this->editingId !== null ? Shift::query()->findOrFail($this->editingId) : null, [
            'name' => $validated['name'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'grace_minutes' => (int) $validated['grace_minutes'],
            'is_default' => (bool) $validated['is_default'],
        ], $this->currentUser());

        $this->cancel();
        unset($this->shifts);
        $this->dispatch('notify', message: 'Shift saved.');
    }

    public function delete(int $id, DeleteShift $deleteShift): void
    {
        $this->authorize(Ability::ConfigureSystem->value);

        $deleteShift->handle(Shift::query()->findOrFail($id), $this->currentUser());
        unset($this->shifts);
        $this->dispatch('notify', message: 'Shift deleted.');
    }

    /**
     * @return Collection<int, Shift>
     */
    #[Computed]
    public function shifts(): Collection
    {
        return Shift::query()->withCount('employeeProfiles')->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function render(): View
    {
        return view('livewire.admin.shifts');
    }
}
