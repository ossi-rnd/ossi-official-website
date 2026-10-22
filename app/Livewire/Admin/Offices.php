<?php

namespace App\Livewire\Admin;

use App\Actions\Settings\DeleteOffice;
use App\Actions\Settings\SaveOffice;
use App\Enums\Ability;
use App\Enums\Region;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Office;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * OSSI's own offices (head office, branches) where office staff work and clock
 * in. Admin and Super Admin keep them; HR picks one on an office staffer's record.
 * Not clients: those are where guards are deployed (Clients page).
 */
#[Title('OSSI Offices')]
class Offices extends Component
{
    use InteractsWithUser;

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $region = '';

    public string $province = '';

    public string $name = '';

    public string $address = '';

    /** "latitude, longitude", filled by GPS, the map or a paste (site-picker.js). */
    public string $coordinates = '';

    public string $geofence_meters = '150';

    private const FIELDS = ['editingId', 'region', 'province', 'name', 'address', 'coordinates', 'geofence_meters'];

    public function create(): void
    {
        $this->authorize(Ability::ManageOffices->value);

        $this->reset(self::FIELDS);
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Ability::ManageOffices->value);

        $office = Office::query()->findOrFail($id);
        $this->editingId = $office->id;
        $this->region = $office->region->value;
        $this->province = $office->province;
        $this->name = $office->name;
        $this->address = (string) $office->address;
        $this->coordinates = $office->hasGeofence() ? (float) $office->latitude.', '.(float) $office->longitude : '';
        $this->geofence_meters = (string) $office->geofence_meters;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    /** A different region has different provinces. */
    public function updatedRegion(): void
    {
        $this->province = '';
    }

    public function cancel(): void
    {
        $this->reset(['formOpen', ...self::FIELDS]);
    }

    public function save(SaveOffice $saveOffice): void
    {
        $this->authorize(Ability::ManageOffices->value);

        $region = Region::tryFrom($this->region);

        $validated = $this->validate([
            'region' => ['required', Rule::enum(Region::class)],
            'province' => ['required', Rule::in($region?->provinces() ?? [])],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'coordinates' => ['nullable', 'string', 'regex:/^\s*-?\d{1,2}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?\s*$/'],
            'geofence_meters' => ['required', 'integer', 'min:30', 'max:2000'],
        ], [
            'province.required' => 'Choose the '.strtolower($region?->areaLabel() ?? 'province').'.',
            'province.in' => 'Choose the '.strtolower($region?->areaLabel() ?? 'province').'.',
            'coordinates.regex' => 'Enter the coordinates as "latitude, longitude", e.g. 14.5547, 121.0244.',
        ]);

        [$latitude, $longitude] = filled($validated['coordinates']) ? array_map(fn (string $part) => (float) trim($part), explode(',', $validated['coordinates'])) : [null, null];

        $saveOffice->handle($this->editingId !== null ? Office::query()->findOrFail($this->editingId) : null, [
            'region' => Region::from($validated['region']),
            'province' => $validated['province'],
            'name' => trim($validated['name']),
            'address' => filled($validated['address']) ? trim($validated['address']) : null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'geofence_meters' => (int) $validated['geofence_meters'],
        ], $this->currentUser());

        $this->cancel();
        unset($this->offices);
        $this->dispatch('notify', message: 'Office saved.');
    }

    public function delete(int $id, DeleteOffice $deleteOffice): void
    {
        $this->authorize(Ability::ManageOffices->value);

        $deleteOffice->handle(Office::query()->findOrFail($id), $this->currentUser());
        unset($this->offices);
        $this->dispatch('notify', message: 'Office deleted.');
    }

    /**
     * @return Collection<int, Office>
     */
    #[Computed]
    public function offices(): Collection
    {
        return Office::query()->withCount('employeeProfiles')->orderBy('region')->orderBy('province')->orderBy('name')->get();
    }

    public function render(): View
    {
        return view('livewire.admin.offices', ['selectedRegion' => Region::tryFrom($this->region)]);
    }
}
