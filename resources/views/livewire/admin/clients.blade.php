<div class="space-y-6">
    {{-- One tab per region that has clients. Scrolls sideways on a phone instead of wrapping. --}}
    <div class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="Regions">
        @foreach (['' => ['All regions', array_sum($this->regionCounts)], ...collect($this->regionCounts)->mapWithKeys(fn ($count, $value) => [$value => [\App\Enums\Region::from($value)->label(), $count]])->all()] as $value => [$label, $count])
            <button type="button" role="tab" wire:click="selectTab('{{ $value }}')" aria-selected="{{ $tab === $value ? 'true' : 'false' }}"
                @class(['-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap', 'border-blue-600 text-blue-600 dark:text-blue-400' => $tab === $value, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $tab !== $value])>
                {{ $label }} <span class="rounded-full bg-gray-100 px-1.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $count }}</span>
            </button>
        @endforeach
    </div>

    @php $tabRegion = \App\Enums\Region::tryFrom($tab); @endphp

    <x-ui.card :title="$tabRegion ? $tabRegion->fullLabel() : 'Clients'" description="Where clients are: Region → Province → Client. HR picks one as a guard's detachment. This is the deployment site, not anyone's home address.">
        <x-slot:actions>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="plus" class="h-4 w-4" /> Add client</x-ui.button>
        </x-slot:actions>

        @error('client') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Client</th><th class="ui-th">Site address</th><th class="ui-th">Contact</th><th class="ui-th">Deployed</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->clients->groupBy(fn ($client) => $client->region->value.'|'.$client->province) as $group => $clients)
                        @php $first = $clients->first(); @endphp
                        <tr wire:key="group-{{ $group }}">
                            <th colspan="5" scope="colgroup" class="bg-gray-50 px-4 py-2 text-left text-xs font-semibold tracking-wide text-gray-600 uppercase dark:bg-gray-800/60 dark:text-gray-300">
                                @unless ($tabRegion) {{ $first->region->label() }} <span class="text-gray-400">→</span> @endunless
                                {{ $first->province }}
                            </th>
                        </tr>
                        @foreach ($clients as $client)
                            <tr wire:key="client-{{ $client->id }}">
                                <td class="ui-td"><button type="button" wire:click="viewGuards({{ $client->id }})" class="text-left font-medium text-gray-800 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">{{ $client->name }}</button></td>
                                <td class="ui-td">
                                    <p>{{ $client->site_address ?? '—' }}</p>
                                    @if ($client->hasGeofence())
                                        <p class="text-xs text-gray-500">Geofence {{ $client->geofence_meters }} m</p>
                                    @else
                                        <p class="text-xs text-amber-700 dark:text-amber-400">No geofence yet</p>
                                    @endif
                                </td>
                                <td class="ui-td">
                                    @if ($client->contact_person || $client->contact_number)
                                        {{ $client->contact_person }}@if ($client->contact_person && $client->contact_number) · @endif{{ $client->contact_number }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="ui-td tabular-nums">
                                    @if ($client->employee_profiles_count > 0)
                                        <button type="button" wire:click="viewGuards({{ $client->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ $client->employee_profiles_count }} {{ str('guard')->plural($client->employee_profiles_count) }}</button>
                                    @else
                                        <span class="text-gray-400">0 guards</span>
                                    @endif
                                </td>
                                <td class="ui-td text-right whitespace-nowrap">
                                    <button type="button" wire:click="edit({{ $client->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                    <x-ui.row-delete wire:click="delete({{ $client->id }})" :disabled-reason="$client->employee_profiles_count > 0 ? $client->employee_profiles_count.' '.str('guard')->plural($client->employee_profiles_count).' deployed here. Move them to another client first.' : null" wire:confirm="Delete {{ $client->name }} ({{ $client->locationLabel() }})?">Delete</x-ui.row-delete>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="5" class="ui-td py-8 text-center">No clients {{ $tabRegion ? 'in '.$tabRegion->label() : '' }} yet. Add one, e.g. Region IV-A → Cavite → Metrobank.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($this->viewing)
        <x-ui.modal :title="$this->viewing->name" close="closeGuards" max-width="4xl">
            {{-- Refreshes every minute while open: it's a live view of who is at the posts. --}}
            <div wire:poll.60s>
            @php
                $today = $this->today;
                $states = collect($today['rows'])->countBy('state');
                $attention = collect($today['rows'])->where('attention', true)->count();
            @endphp
            <div class="flex flex-wrap items-start justify-between gap-3 text-sm">
                <div>
                    <p class="text-gray-600 dark:text-gray-300">{{ $this->viewing->province }}, {{ $this->viewing->region->fullLabel() }}</p>
                    @if ($this->viewing->site_address) <p class="text-xs text-gray-500">{{ $this->viewing->site_address }}</p> @endif
                    @if ($this->viewing->contact_person || $this->viewing->contact_number)
                        <p class="text-xs text-gray-500">Contact: {{ collect([$this->viewing->contact_person, $this->viewing->contact_number])->filter()->implode(' · ') }}</p>
                    @endif
                </div>
                <x-ui.badge tone="blue">{{ $this->guards->count() }} {{ str('guard')->plural($this->guards->count()) }} deployed</x-ui.badge>
            </div>

            {{-- Today at a glance. --}}
            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ([['On duty', $states['on_duty'] ?? 0, 'text-gray-800 dark:text-gray-100'], ['Not in', $states['not_in'] ?? 0, ($states['not_in'] ?? 0) > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-gray-100'], ['Absent', ($states['absent_open'] ?? 0) + ($states['absent_covered'] ?? 0), 'text-gray-800 dark:text-gray-100'], ['Shift not started', $states['upcoming'] ?? 0, 'text-gray-800 dark:text-gray-100']] as [$label, $count, $tone])
                    <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800">
                        <p class="text-xs text-gray-500">{{ $label }}</p>
                        <p class="text-lg font-semibold tabular-nums {{ $tone }}">{{ $count }}</p>
                    </div>
                @endforeach
            </div>

            @if ($attention > 0)
                <x-ui.alert tone="red" class="mt-3">
                    {{ $attention }} {{ str('post')->plural($attention) }} may be uncovered: a guard is past their grace period without clocking in, or sent an absence notice with no reliever yet.
                    @can(\App\Enums\Ability::ManageRelievers->value)
                        <a href="{{ route('relievers') }}" wire:navigate class="font-medium underline">Open Relievers</a>
                    @endcan
                </x-ui.alert>
            @endif

            <div class="mt-4 overflow-x-auto rounded-md border border-gray-200 dark:border-gray-800">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Guard</th><th class="ui-th">Post</th><th class="ui-th">Shift</th><th class="ui-th">Today</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->guards as $guard)
                            <tr wire:key="guard-{{ $guard->id }}">
                                <td class="ui-td">
                                    <p><x-person-link :profile="$guard" /></p>
                                    <p class="text-xs text-gray-500">{{ $guard->employee_number }}</p>
                                </td>
                                <td class="ui-td">
                                    <p>{{ $guard->post ?? '—' }}</p>
                                    <p class="text-xs text-gray-500">{{ $guard->position?->label() }}</p>
                                </td>
                                <td class="ui-td whitespace-nowrap">{{ $guard->shift?->label() ?? 'Default shift' }}</td>
                                <td class="ui-td">
                                    @php $now = $today['rows'][$guard->id] ?? null; @endphp
                                    @if ($now)
                                        <x-ui.badge :tone="$now['tone']">{{ $now['label'] }}</x-ui.badge>
                                        @if ($now['detail']) <p class="mt-1 max-w-56 truncate text-xs text-gray-500" title="{{ $now['detail'] }}">{{ $now['detail'] }}</p> @endif
                                    @endif
                                </td>
                                <td class="ui-td"><x-ui.badge :tone="$guard->employment_status->isActive() ? 'gray' : 'red'">{{ $guard->employment_status->label() }}</x-ui.badge></td>
                                <td class="ui-td text-right">
                                    @can(\App\Enums\Ability::ViewPeople->value)
                                        <a href="{{ route('employees.show', $guard) }}" wire:navigate class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">View</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="ui-td py-8 text-center">No guards deployed here yet. HR picks this client as a guard's detachment on the employee record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($today['relief'] !== [])
                <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50/60 p-3 text-sm dark:border-blue-500/20 dark:bg-blue-500/5">
                    <p class="font-medium text-gray-800 dark:text-gray-100">Relievers here today</p>
                    <ul class="mt-1 space-y-0.5 text-gray-600 dark:text-gray-300">
                        @foreach ($today['relief'] as $relief)
                            <li>{{ $relief['reliever'] }} covers for {{ $relief['for'] }}{{ $relief['post'] ? ' at '.$relief['post'] : '' }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="mt-3 text-xs text-gray-400">Updated {{ now()->format('g:i A') }} · refreshes every minute.</p>
            </div>
        </x-ui.modal>
    @endif

    @if ($formOpen)
        <x-ui.modal :title="$editingId ? 'Edit client' : 'Add client'" close="cancel" max-width="md">
            <form id="client-form" wire:submit="save" class="space-y-4">
                <x-ui.select label="Region" wire:model.live="region">
                    <option value="">Select region</option>
                    @foreach (\App\Enums\Region::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->fullLabel() }}</option>
                    @endforeach
                </x-ui.select>

                {{-- wire:key: the list changes with the region, so the custom select must re-mount. --}}
                <div wire:key="province-{{ $region ?: 'none' }}">
                    <x-ui.select :label="$selectedRegion?->areaLabel() ?? 'Province'" wire:model="province" :disabled="$selectedRegion === null">
                        <option value="">{{ $selectedRegion ? 'Select '.strtolower($selectedRegion->areaLabel()) : 'Choose the region first' }}</option>
                        @foreach ($selectedRegion?->provinces() ?? [] as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input label="Client" wire:model="name" placeholder="e.g. Metrobank – Imus" />
                <x-ui.input label="Site address (optional)" wire:model="site_address" placeholder="Street, barangay, city" />
                @include('livewire.admin.partials.site-location-fields', ['who' => 'Guards', 'place' => 'post'])
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input label="Contact person (optional)" wire:model="contact_person" placeholder="e.g. Ana Santos" autocapitalize="words" maxlength="255"
                        x-on:input="$el.value = $el.value.replace(/[^\p{L}\p{M} .,'-]/gu, '')" />
                    <x-ui.input label="Contact number (optional)" wire:model="contact_number" type="tel" inputmode="tel" placeholder="0917 123 4567 or (02) 8123 4567" maxlength="20"
                        x-on:input="$el.value = $el.value.replace(/[^\d\s()+-]/g, '')" />
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button form="client-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
