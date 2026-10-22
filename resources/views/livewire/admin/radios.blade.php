<div class="space-y-6">
    @php
        $today = \Carbon\CarbonImmutable::today();
        $filters = ['all' => 'All', 'due' => 'Permit due', ...collect(\App\Enums\RadioStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()];
    @endphp

    <x-ui.card title="Radios" description="The NTC masterlist: every two-way radio, its NTC permit, and where it is. Admin and Operation HQ are reminded 60, 30 and 7 days before a permit expires.">
        <x-slot:actions>
            <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Serial, permit, post or guard" aria-label="Search radios" class="w-full sm:w-56" />
            <x-ui.button type="button" variant="dark" size="sm" wire:click="create">Add radio</x-ui.button>
        </x-slot:actions>

        {{-- Filters: all, permits due (within 30 days or expired), and each status. --}}
        <div class="flex gap-1 overflow-x-auto border-b border-gray-200 px-3 dark:border-gray-800" role="tablist" aria-label="Show">
            @foreach ($filters as $value => $label)
                <button type="button" role="tab" wire:click="$set('show', '{{ $value }}')" aria-selected="{{ $show === $value ? 'true' : 'false' }}"
                    @class(['-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap', 'border-blue-600 text-blue-600 dark:text-blue-400' => $show === $value, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $show !== $value])>
                    {{ $label }}
                    @if ($value === 'due' && ($this->counts['due'] ?? 0) > 0)
                        <span class="rounded-full bg-amber-100 px-1.5 text-xs text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ $this->counts['due'] }}</span>
                    @elseif ($value !== 'due')
                        <span class="text-xs text-gray-400 tabular-nums">{{ $this->counts[$value] ?? 0 }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Radio</th><th class="ui-th">NTC permit</th><th class="ui-th">Where</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->radios as $radio)
                        @php $days = $radio->daysToExpiry($today); @endphp
                        <tr wire:key="radio-{{ $radio->id }}">
                            <td class="ui-td">
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ $radio->brand }} {{ $radio->model }}</p>
                                <p class="font-mono text-xs text-gray-500">SN {{ $radio->serial_number }}{{ $radio->frequency ? ' · '.$radio->frequency : '' }}</p>
                            </td>
                            <td class="ui-td whitespace-nowrap">
                                <p>{{ $radio->ntc_permit_number ?? '—' }}</p>
                                @if ($radio->permit_expires_on)
                                    <p class="text-xs text-gray-500">Expires {{ $radio->permit_expires_on->format('M j, Y') }}</p>
                                    @if ($radio->status->needsPermit() && $days < 0)
                                        <x-ui.badge tone="red">Expired {{ abs($days) }} {{ str('day')->plural(abs($days)) }} ago</x-ui.badge>
                                    @elseif ($radio->status->needsPermit() && $days <= \App\Models\Radio::DUE_SOON_DAYS)
                                        <x-ui.badge tone="amber">{{ $days === 0 ? 'Expires today' : "Expires in {$days} ".str('day')->plural($days) }}</x-ui.badge>
                                    @endif
                                @elseif ($radio->status->needsPermit())
                                    <p class="text-xs text-amber-700 dark:text-amber-400">No expiry date yet</p>
                                @endif
                            </td>
                            <td class="ui-td">{{ $radio->whereabouts() }}</td>
                            <td class="ui-td"><x-ui.badge :tone="$radio->status->tone()">{{ $radio->status->label() }}</x-ui.badge></td>
                            <td class="ui-td text-right whitespace-nowrap">
                                <button type="button" wire:click="edit({{ $radio->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                <x-ui.row-delete wire:click="delete({{ $radio->id }})" wire:confirm="Delete radio SN {{ $radio->serial_number }}? Only for one added by mistake: a radio out of service is marked Disposed or Lost instead, so the masterlist keeps it.">Delete</x-ui.row-delete>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-td py-8 text-center">{{ $search !== '' || $show !== 'all' ? 'No radios match.' : 'No radios yet. Add each one with its serial number and NTC permit.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal :title="$editingId ? 'Edit radio' : 'Add radio'" close="cancel" max-width="lg">
            <form id="radio-form" wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input label="Brand" wire:model="brand" placeholder="e.g. Motorola" maxlength="80" />
                    <x-ui.input label="Model" wire:model="model" placeholder="e.g. XiR P3688" maxlength="80" />
                    <x-ui.input label="Serial number" wire:model="serialNumber" placeholder="As printed on the radio" maxlength="80" autocomplete="off" spellcheck="false"
                        x-on:input="$el.value = $el.value.toUpperCase().replace(/[^A-Z0-9\-\/ ]/g, '')" />
                    <x-ui.input label="Frequency (optional)" wire:model="frequency" placeholder="e.g. 150.250 MHz" maxlength="80" />
                    <x-ui.input label="NTC permit number (optional)" wire:model="permitNumber" placeholder="On the NTC permit" maxlength="80" autocomplete="off" />
                    <x-ui.input label="Permit expires (optional)" type="date" wire:model="permitExpiresOn" hint="The reminders count down to this date." />
                </div>

                <x-ui.select label="Status" wire:model.live="status">
                    @foreach (\App\Enums\RadioStatus::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>

                {{-- Only a radio in use is somewhere: at a post, with a guard, or both. --}}
                @if ($status === \App\Enums\RadioStatus::InUse->value)
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.select label="At post (client)" wire:model="clientId" searchable>
                            <option value="">Not at a post</option>
                            @foreach ($this->clients as $client)
                                <option value="{{ $client->id }}">{{ $client->name }} · {{ $client->locationLabel() }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.select label="With guard" wire:model="assignedUserId" searchable>
                            <option value="">Not with one guard</option>
                            @foreach ($this->guards as $guard)
                                <option value="{{ $guard->id }}">{{ $guard->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                @endif

                <x-ui.textarea label="Notes (optional)" wire:model="notes" rows="2" maxlength="500" placeholder="e.g. Battery replaced Oct 2026" />
            </form>
            <x-slot:footer>
                <x-ui.button form="radio-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
