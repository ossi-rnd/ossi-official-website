<div class="space-y-6">
    <x-ui.card title="Shifts" description="Late = minutes after start + grace. Undertime = minutes before end. A shift whose end is before its start runs overnight (e.g. 22:00 – 06:00).">
        <x-slot:actions>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="clock-plus" class="h-4 w-4" /> Add shift</x-ui.button>
        </x-slot:actions>

        @error('shift') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Name</th><th class="ui-th">Hours</th><th class="ui-th">Grace</th><th class="ui-th">Employees</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->shifts as $shift)
                        <tr wire:key="shift-{{ $shift->id }}">
                            <td class="ui-td font-medium text-gray-800 dark:text-gray-100">
                                {{ $shift->name }}
                                @if ($shift->is_default) <x-ui.badge tone="blue" class="ml-1">Default</x-ui.badge> @endif
                                @if ($shift->crossesMidnight()) <x-ui.badge tone="purple" class="ml-1">Overnight</x-ui.badge> @endif
                            </td>
                            <td class="ui-td">{{ substr($shift->start_time, 0, 5) }} – {{ substr($shift->end_time, 0, 5) }} ({{ round($shift->scheduledMinutes() / 60, 1) }}h)</td>
                            <td class="ui-td">{{ $shift->grace_minutes }} min</td>
                            <td class="ui-td">{{ $shift->employee_profiles_count }}</td>
                            <td class="ui-td text-right">
                                <button type="button" wire:click="edit({{ $shift->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                <x-ui.row-delete wire:click="delete({{ $shift->id }})" :disabled-reason="$shift->employee_profiles_count > 0 ? $shift->employee_profiles_count.' '.str('employee')->plural($shift->employee_profiles_count).' on this shift. Move them to another shift first.' : null" wire:confirm="Delete {{ $shift->name }}?">Delete</x-ui.row-delete>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-td py-8 text-center">No shifts yet. Everyone uses the fallback 09:00 – 18:00 shift.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal :title="$editingId ? 'Edit shift' : 'Add shift'" close="cancel" max-width="sm">
            <form id="shift-form" wire:submit="save" class="space-y-4">
                <x-ui.input label="Name" wire:model="name" placeholder="e.g. Night Shift" />
                <div class="grid grid-cols-2 gap-4">
                    <x-ui.input label="Start" type="time" wire:model="start_time" />
                    <x-ui.input label="End" type="time" wire:model="end_time" />
                </div>
                <x-ui.input label="Grace minutes" type="number" min="0" max="120" wire:model="grace_minutes" />
                <x-ui.checkbox label="Default shift" description="Used for anyone without a shift assigned." wire:model="is_default" />
            </form>
            <x-slot:footer>
                <x-ui.button form="shift-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
