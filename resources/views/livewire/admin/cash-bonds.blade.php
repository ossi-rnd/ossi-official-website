@php
    $peso = fn (float $amount): string => '₱'.number_format($amount, 2);
@endphp

<div>
    <x-ui.card title="Cash Bonds" description="₱100 a cut-off (₱200 a month) from each payslip, refunded in full in final pay after what's owed. Enter once what each person paid in before the system.">
        <x-slot:actions>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name…" aria-label="Search" class="ui-field mt-0! sm:w-48">
            <x-ui.select wire:model.live="who" aria-label="Who">
                <option value="guards">Guards</option>
                <option value="all">Everyone on payroll</option>
            </x-ui.select>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="enrollAllGuards" wire:target="enrollAllGuards" wire:confirm="Start the ₱100-a-cut-off cash bond for every guard not yet enrolled?">Enroll all guards</x-ui.button>
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Name</th><th class="ui-th text-right">A cut-off</th><th class="ui-th text-right">Paid in before</th><th class="ui-th text-right">From payslips</th><th class="ui-th text-right">Corrections / refunds</th><th class="ui-th text-right">Balance</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->people as $person)
                        @php $bond = $this->balances[$person->id] ?? ['per_cutoff' => 0.0, 'opening' => 0.0, 'collected' => 0.0, 'adjustments' => 0.0, 'refunded' => 0.0, 'balance' => 0.0]; @endphp
                        <tr wire:key="bond-{{ $person->id }}">
                            <td class="ui-td">
                                <p><x-person-link :user="$person" /></p>
                                <p class="text-xs text-gray-500">{{ $person->employeeProfile?->assignmentLabel() ?? '—' }}</p>
                            </td>
                            <td class="ui-td text-right tabular-nums">
                                @if ($bond['per_cutoff'] > 0) {{ $peso($bond['per_cutoff']) }}
                                @else <button type="button" wire:click="enroll({{ $person->id }})" class="text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Start</button>
                                @endif
                            </td>
                            <td class="ui-td text-right tabular-nums">
                                @if ($bond['opening'] > 0) {{ $peso($bond['opening']) }}
                                @else <button type="button" wire:click="startEntry({{ $person->id }}, 'opening')" class="text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Enter</button>
                                @endif
                            </td>
                            <td class="ui-td text-right text-gray-600 tabular-nums dark:text-gray-300">{{ $peso($bond['collected']) }}</td>
                            <td class="ui-td text-right text-gray-600 tabular-nums dark:text-gray-300">{{ $bond['adjustments'] + $bond['refunded'] == 0 ? '—' : $peso($bond['adjustments'] + $bond['refunded']) }}</td>
                            <td class="ui-td text-right font-semibold tabular-nums">{{ $peso($bond['balance']) }}</td>
                            <td class="ui-td text-right"><button type="button" wire:click="startEntry({{ $person->id }}, 'adjustment')" class="text-sm font-medium text-gray-500 hover:text-blue-600 dark:hover:text-blue-400">Correct</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-td py-8 text-center">Nobody here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>{{ $this->people->links() }}</x-slot:footer>
    </x-ui.card>

    @if ($editing = $this->editing)
        <x-ui.modal :title="($kind === 'opening' ? 'Starting balance' : 'Correction').' · '.$editing->name" close="cancelEntry" max-width="md">
            <form id="bond-form" wire:submit="saveEntry" class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $kind === 'opening'
                        ? 'What they paid into the cash bond before this system, as on your records. Entered once; later changes are corrections.'
                        : 'A correction, + to add or − to take off, with the reason. It never edits earlier entries.' }}
                </p>
                <x-ui.input label="Amount (₱)" type="number" step="0.01" wire:model="amount" :min="$kind === 'opening' ? 0 : null" />
                <x-ui.input :label="$kind === 'opening' ? 'Note (optional)' : 'Reason'" wire:model="note" maxlength="255" />
            </form>
            <x-slot:footer>
                <x-ui.button form="bond-form" wire:target="saveEntry">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
