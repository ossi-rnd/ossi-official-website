<?php

namespace App\Livewire\Admin;

use App\Actions\Settings\DeleteHoliday;
use App\Actions\Settings\SaveHoliday;
use App\Enums\Ability;
use App\Enums\HolidayType;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Holidays')]
class Holidays extends Component
{
    use InteractsWithUser;

    #[Url]
    public int $year = 0;

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $date = '';

    public string $name = '';

    public string $type = 'regular';

    public function mount(): void
    {
        $this->year = $this->year > 0 ? $this->year : CarbonImmutable::today()->year;
    }

    public function create(): void
    {
        $this->authorize(Ability::ConfigureHolidays->value);

        $this->reset(['editingId', 'date', 'name', 'type']);
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Ability::ConfigureHolidays->value);

        $holiday = Holiday::query()->findOrFail($id);
        $this->editingId = $holiday->id;
        $this->date = $holiday->date->toDateString();
        $this->name = $holiday->name;
        $this->type = $holiday->type->value;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset(['formOpen', 'editingId', 'date', 'name', 'type']);
    }

    public function save(SaveHoliday $saveHoliday): void
    {
        $this->authorize(Ability::ConfigureHolidays->value);

        $validated = $this->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(HolidayType::class)],
        ]);

        $saveHoliday->handle($this->editingId !== null ? Holiday::query()->findOrFail($this->editingId) : null, $validated, $this->currentUser());

        $this->cancel();
        unset($this->holidays);
        $this->dispatch('notify', message: 'Holiday saved.');
    }

    public function delete(int $id, DeleteHoliday $deleteHoliday): void
    {
        $this->authorize(Ability::ConfigureHolidays->value);

        $deleteHoliday->handle(Holiday::query()->findOrFail($id), $this->currentUser());
        unset($this->holidays);
        $this->dispatch('notify', message: 'Holiday deleted.');
    }

    /**
     * @return Collection<int, Holiday>
     */
    #[Computed]
    public function holidays(): Collection
    {
        return Holiday::query()->whereYear('date', $this->year)->orderBy('date')->get();
    }

    public function render(): View
    {
        return view('livewire.admin.holidays', ['types' => HolidayType::cases()]);
    }
}
