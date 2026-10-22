<div class="space-y-6">
    <x-ui.card title="OSSI Offices" description="Where office staff work: head office and branches. HR picks one on an office staffer's record, and their clock-in must be inside its circle. Client sites for guards are on Clients.">
        <x-slot:actions>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="plus" class="h-4 w-4" /> Add office</x-ui.button>
        </x-slot:actions>

        @error('office') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Office</th><th class="ui-th">Address</th><th class="ui-th">Staff</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->offices as $office)
                        <tr wire:key="office-{{ $office->id }}">
                            <td class="ui-td">
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ $office->name }}</p>
                                <p class="text-xs text-gray-500">{{ $office->locationLabel() }}</p>
                            </td>
                            <td class="ui-td">
                                <p>{{ $office->address ?? '—' }}</p>
                                @if ($office->hasGeofence())
                                    <p class="text-xs text-gray-500">Geofence {{ $office->geofence_meters }} m</p>
                                @else
                                    <p class="text-xs text-amber-700 dark:text-amber-400">No geofence yet: staff here can clock in anywhere</p>
                                @endif
                            </td>
                            <td class="ui-td tabular-nums">{{ $office->employee_profiles_count }} {{ str('person')->plural($office->employee_profiles_count) }}</td>
                            <td class="ui-td text-right whitespace-nowrap">
                                <button type="button" wire:click="edit({{ $office->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                <x-ui.row-delete wire:click="delete({{ $office->id }})" :disabled-reason="$office->employee_profiles_count > 0 ? $office->employee_profiles_count.' staff work here. Move them to another office first.' : null" wire:confirm="Delete {{ $office->name }} ({{ $office->locationLabel() }})?">Delete</x-ui.row-delete>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-td py-8 text-center">No offices yet. Add one, e.g. NCR → Quezon City → OSSI Head Office.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal :title="$editingId ? 'Edit office' : 'Add office'" close="cancel" max-width="md">
            <form id="office-form" wire:submit="save" class="space-y-4">
                <x-ui.select label="Region" wire:model.live="region">
                    <option value="">Select region</option>
                    @foreach (\App\Enums\Region::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->fullLabel() }}</option>
                    @endforeach
                </x-ui.select>

                {{-- wire:key: the list changes with the region, so the custom select must re-mount. --}}
                <div wire:key="office-province-{{ $region ?: 'none' }}">
                    <x-ui.select :label="$selectedRegion?->areaLabel() ?? 'Province'" wire:model="province" :disabled="$selectedRegion === null">
                        <option value="">{{ $selectedRegion ? 'Select '.strtolower($selectedRegion->areaLabel()) : 'Choose the region first' }}</option>
                        @foreach ($selectedRegion?->provinces() ?? [] as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input label="Office" wire:model="name" placeholder="e.g. OSSI Head Office" />
                <x-ui.input label="Address (optional)" wire:model="address" placeholder="Street, barangay, city" />
                @include('livewire.admin.partials.site-location-fields', ['who' => 'Office staff', 'place' => 'office'])
            </form>
            <x-slot:footer>
                <x-ui.button form="office-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
