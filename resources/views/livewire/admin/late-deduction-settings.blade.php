<div class="space-y-6">
    <x-ui.card title="Late Deduction Settings" description="A fixed deduction per bracket of total late minutes in a cut-off. Brackets can't overlap. With NO brackets, late minutes are deducted at the per-minute rate (daily rate ÷ 480).">
        <x-slot:actions>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="clock-alert" class="h-4 w-4" /> Add bracket</x-ui.button>
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Late minutes in the cut-off</th><th class="ui-th">Deduction</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->rules as $rule)
                        <tr wire:key="rule-{{ $rule->id }}">
                            <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $rule->rangeLabel() }}</td>
                            <td class="ui-td"><x-money :amount="$rule->deduction_amount" /></td>
                            <td class="ui-td text-right">
                                <button type="button" wire:click="edit({{ $rule->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                <x-ui.row-delete wire:click="delete({{ $rule->id }})" wire:confirm="Delete this bracket?">Delete</x-ui.row-delete>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="ui-td py-8 text-center">No brackets set: late minutes are deducted at the per-minute rate.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal :title="$editingId ? 'Edit bracket' : 'Add bracket'" close="cancel" max-width="sm">
            <form id="late-rule" wire:submit="save" class="space-y-4">
                <x-ui.input label="From (minutes late)" type="number" min="0" wire:model="minMinutes" />
                <x-ui.input label="Up to (minutes late)" type="number" min="0" wire:model="maxMinutes" hint="Leave blank for “and above”." />
                <x-ui.input label="Deduction (₱)" type="number" step="0.01" min="0" wire:model="deductionAmount" />
            </form>
            <x-slot:footer>
                <x-ui.button form="late-rule">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
