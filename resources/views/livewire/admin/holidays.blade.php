<div class="space-y-6">
    <x-ui.card :title="'Holidays '.$year" description="A worked regular holiday adds 100% of the daily rate; a special non-working day adds 30%. Unworked holidays add nothing.">
        <x-slot:actions>
            <div class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-1 py-0.5 dark:border-gray-700">
                <button type="button" wire:click="$set('year', {{ $year - 1 }})" aria-label="Previous year" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/10"><x-icon name="chevron-left" class="h-4 w-4" /></button>
                <span class="px-1 text-sm font-medium">{{ $year }}</span>
                <button type="button" wire:click="$set('year', {{ $year + 1 }})" aria-label="Next year" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/10"><x-icon name="chevron-right" class="h-4 w-4" /></button>
            </div>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="calendar-plus" class="h-4 w-4" /> Add holiday</x-ui.button>
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Date</th><th class="ui-th">Name</th><th class="ui-th">Type</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->holidays as $holiday)
                        <tr wire:key="holiday-{{ $holiday->id }}">
                            <td class="ui-td font-medium whitespace-nowrap text-gray-800 dark:text-gray-100">{{ $holiday->date->format('D, M j, Y') }}</td>
                            <td class="ui-td">{{ $holiday->name }}</td>
                            <td class="ui-td"><x-ui.badge :tone="$holiday->type === \App\Enums\HolidayType::Regular ? 'indigo' : 'blue'">{{ $holiday->type->label() }}</x-ui.badge></td>
                            <td class="ui-td text-right">
                                <button type="button" wire:click="edit({{ $holiday->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                <x-ui.row-delete wire:click="delete({{ $holiday->id }})" wire:confirm="Delete {{ $holiday->name }}?">Delete</x-ui.row-delete>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-td py-8 text-center">No holidays for {{ $year }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal :title="$editingId ? 'Edit holiday' : 'Add holiday'" close="cancel" max-width="sm">
            <form id="holiday-form" wire:submit="save" class="space-y-4">
                <x-ui.input label="Date" type="date" wire:model="date" />
                <x-ui.input label="Name" wire:model="name" />
                <x-ui.select label="Type" wire:model="type">
                    @foreach ($types as $holidayType)
                        <option value="{{ $holidayType->value }}">{{ $holidayType->label() }}</option>
                    @endforeach
                </x-ui.select>
            </form>
            <x-slot:footer>
                <x-ui.button form="holiday-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
