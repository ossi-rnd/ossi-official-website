@php
    $eventTone = fn (string $event) => match (true) {
        str_ends_with($event, 'failed'), str_ends_with($event, 'rejected'), str_ends_with($event, 'deleted') => 'red',
        str_ends_with($event, 'approved'), str_ends_with($event, 'succeeded'), str_ends_with($event, 'generated'), str_ends_with($event, 'paid') => 'green',
        str_ends_with($event, 'created') => 'blue',
        default => 'gray',
    };
    $show = fn (mixed $value) => is_scalar($value) || $value === null ? (string) ($value ?? '∅') : json_encode($value);
@endphp

<x-ui.card title="Audit Log" description="Who did what, and when: approvals, payslip generation, role changes, employee edits and every registration attempt. Entries can't be edited or deleted.">
    <x-slot:actions>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search…" aria-label="Search" class="ui-field mt-0! sm:w-44">
        <x-ui.select wire:model.live="area" aria-label="Area">
            <option value="all">All areas</option>
            @foreach ($areas as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </x-ui.select>
        <input type="date" wire:model.live="dateFrom" aria-label="From" class="ui-field mt-0!">
        <input type="date" wire:model.live="dateTo" aria-label="To" class="ui-field mt-0!">
    </x-slot:actions>

    <div class="overflow-x-auto">
        <table class="ui-table">
            <thead><tr><th class="ui-th">When</th><th class="ui-th">By</th><th class="ui-th">Area</th><th class="ui-th">Event</th><th class="ui-th">What happened</th><th class="ui-th">Details</th></tr></thead>
            <tbody>
                @forelse ($this->entries as $entry)
                    @php $properties = $entry->properties ?? []; @endphp
                    <tr wire:key="entry-{{ $entry->id }}" class="align-top">
                        <td class="ui-td whitespace-nowrap">{{ $entry->created_at->format('M j, Y g:i A') }}<span class="block text-xs text-gray-400">{{ $entry->ip_address }}</span></td>
                        <td class="ui-td text-gray-800 dark:text-gray-100">{{ $entry->causer?->name ?? 'Guest / system' }}</td>
                        <td class="ui-td">{{ $entry->log_name->label() }}</td>
                        <td class="ui-td"><x-ui.badge :tone="$eventTone($entry->event)" class="font-mono">{{ $entry->event }}</x-ui.badge></td>
                        <td class="ui-td">{{ $entry->description }}</td>
                        <td class="ui-td max-w-sm text-xs">
                            @if (isset($properties['new']) && is_array($properties['new']))
                                <ul class="space-y-0.5">
                                    @foreach ($properties['new'] as $field => $value)
                                        <li>
                                            <span class="font-medium text-gray-700 dark:text-gray-200">{{ str($field)->replace('_', ' ')->ucfirst() }}:</span>
                                            @if (isset($properties['old']) && is_array($properties['old']) && array_key_exists($field, $properties['old']))
                                                <span class="text-gray-400 line-through">{{ $show($properties['old'][$field]) }}</span>
                                            @endif
                                            {{ $show($value) }}
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif ($properties !== [])
                                <ul class="space-y-0.5">
                                    @foreach (\Illuminate\Support\Arr::except($properties, ['old']) as $field => $value)
                                        <li><span class="font-medium text-gray-700 dark:text-gray-200">{{ str($field)->replace('_', ' ')->ucfirst() }}:</span> {{ $show($value) }}</li>
                                    @endforeach
                                </ul>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ui-td py-8 text-center">Nothing recorded for these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $this->entries->links() }}</x-slot:footer>
</x-ui.card>
