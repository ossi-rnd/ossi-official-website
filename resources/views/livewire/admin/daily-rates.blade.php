@php
    $peso = fn (float $amount): string => '₱'.number_format($amount, 2);
@endphp

<div class="space-y-6">
    <x-ui.alert tone="blue" class="text-sm">
        Accounting sets pay here; HR sees it on the employee record. Each employee is paid their <b>personal rate</b> if they have one, else their <b>position's rate</b>, @if ($this->fieldDefault > 0 || $this->officeDefault > 0) else the company default (₱{{ number_format($this->fieldDefault) }} field / ₱{{ number_format($this->officeDefault) }} office). @else otherwise they have <b>no rate</b>: a new hire starts at ₱0 until you set one, and payroll won't generate their payslip. @endif A change takes effect <b>from a date</b>: payroll periods starting on or after it use it, and generated payslips never change.
    </x-ui.alert>

    <div class="flex gap-1 border-b border-gray-200 dark:border-gray-800" role="tablist">
        @foreach (['employees' => 'Employees', 'positions' => 'Rate per position'] as $value => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $value }}')" aria-selected="{{ $tab === $value ? 'true' : 'false' }}"
                @class(['-mb-px border-b-2 px-3 py-2.5 text-sm font-medium', 'border-blue-600 text-blue-600 dark:text-blue-400' => $tab === $value, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $tab !== $value])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'positions')
        <x-ui.card title="Rate per position" description="The company's daily rate for each position. Everyone in that position without a personal rate is paid it.">
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Position</th><th class="ui-th text-right">Daily rate today</th><th class="ui-th">Next change</th><th class="ui-th text-right">Paid this rate</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($this->positionRows as $row)
                            @php
                                $office = $row['position']->isOffice();
                                $paid = $row['rate'] ?? ($office ? $this->officeDefault : $this->fieldDefault);
                            @endphp
                            <tr wire:key="position-{{ $row['position']->value }}">
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $row['position']->label() }}</td>
                                <td class="ui-td text-right whitespace-nowrap">
                                    @include('livewire.admin.partials.rate-amount', ['amount' => $paid, 'muted' => $row['rate'] === null])
                                    @if ($row['rate'] === null && $paid > 0)<x-ui.badge tone="amber" class="ml-1">Not set · default</x-ui.badge>@endif
                                </td>
                                <td class="ui-td whitespace-nowrap">@if ($row['next']) {{ $peso((float) $row['next']->daily_rate) }} from {{ $row['next']->effective_from->format('M j, Y') }} @else — @endif</td>
                                <td class="ui-td text-right tabular-nums">{{ $row['employees'] }} {{ str('employee')->plural($row['employees']) }}</td>
                                <td class="ui-td text-right">
                                    <button type="button" wire:click="startPositionChange('{{ $row['position']->value }}')" class="font-medium whitespace-nowrap text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ $row['rate'] === null ? 'Set rate' : 'Change rate' }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-gray-100 p-4 text-xs text-gray-500 dark:border-gray-800">"Paid this rate" counts active employees in the position without a personal rate. A personal rate (Employees tab) always wins over the position's.</p>
        </x-ui.card>
    @else
        @if ($this->missingCount > 0)
            <x-ui.alert tone="amber">
                {{ $this->missingCount }} {{ str('employee')->plural($this->missingCount) }} {{ $this->missingCount === 1 ? 'has' : 'have' }} neither a personal nor a position rate{{ $this->fieldDefault > 0 ? ', so payroll uses the company default.' : ', so their payslips can\'t be generated yet.' }}
                @if ($filter !== 'missing') <button type="button" wire:click="$set('filter', 'missing')" class="font-medium underline">Show them</button> @endif
            </x-ui.alert>
        @endif

        <x-ui.card>
            <div class="flex flex-col gap-3 border-b border-gray-100 p-4 sm:flex-row sm:items-center dark:border-gray-800">
                <div class="flex-1">
                    <label for="rate-search" class="sr-only">Search</label>
                    <input id="rate-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search name or employee #" class="ui-field mt-0!" />
                </div>
                <div class="flex gap-1" role="tablist">
                    @foreach (['all' => 'All', 'missing' => 'No rate yet'] as $value => $label)
                        <button type="button" role="tab" aria-selected="{{ $filter === $value ? 'true' : 'false' }}" wire:click="$set('filter', '{{ $value }}')"
                            @class(['rounded-md px-3 py-2 text-sm font-medium', 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' => $filter === $value, 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $filter !== $value])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Employee</th><th class="ui-th text-right">Daily rate today</th><th class="ui-th">From</th><th class="ui-th">Next change</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->profiles as $profile)
                            @php
                                $resolved = $this->resolved[$profile->id];
                                $next = $this->upcoming[$profile->id] ?? null;
                                $personal = $resolved->source === \App\Enums\RateSource::Personal;
                            @endphp
                            <tr wire:key="rate-row-{{ $profile->id }}">
                                <td class="ui-td">
                                    <p class="font-medium text-gray-800 dark:text-gray-100">{{ $profile->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $profile->employee_number }} · {{ $profile->position?->label() ?? ($profile->is_office_staff ? 'Office' : 'Field').', no position' }}</p>
                                </td>
                                <td class="ui-td text-right whitespace-nowrap">
                                    @include('livewire.admin.partials.rate-amount', ['amount' => $resolved->rate, 'muted' => $resolved->source === \App\Enums\RateSource::Default])
                                </td>
                                <td class="ui-td whitespace-nowrap">
                                    <x-ui.badge :tone="match ($resolved->source) { \App\Enums\RateSource::Personal => 'blue', \App\Enums\RateSource::Position => 'gray', default => 'amber' }">{{ $resolved->rate > 0 ? $resolved->source->label() : 'No rate' }}</x-ui.badge>
                                </td>
                                <td class="ui-td whitespace-nowrap">@if ($next) {{ $peso((float) $next->daily_rate) }} from {{ $next->effective_from->format('M j, Y') }} @else — @endif</td>
                                <td class="ui-td text-right">
                                    <button type="button" wire:click="startRateChange({{ $profile->id }})" class="font-medium whitespace-nowrap text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ $personal ? 'Change rate' : 'Set personal rate' }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="ui-td py-8 text-center">{{ $filter === 'missing' ? 'Everyone has a rate.' : 'No employees match.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($this->profiles->hasPages())
                <div class="border-t border-gray-100 p-4 dark:border-gray-800">{{ $this->profiles->links() }}</div>
            @endif
        </x-ui.card>
    @endif

    @if ($editingId !== null || $editingPosition !== null)
        @php
            $forPosition = $editingPosition !== null;
            $title = $forPosition ? 'Position rate · '.\App\Enums\Position::from($editingPosition)->label() : 'Personal rate · '.($this->editingProfile?->name ?? '');
            $history = $forPosition ? $this->positionHistory : $this->history;
        @endphp
        <x-ui.modal :title="$title" close="closeForm">
            <form id="change-rate" wire:submit="{{ $forPosition ? 'savePositionRate' : 'saveRate' }}" class="space-y-4">
                @if ($forPosition)
                    <p class="text-sm text-gray-600 dark:text-gray-300">Applies to everyone in this position <b>without a personal rate</b>.</p>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-300">Overrides the position's rate for this person only.</p>
                @endif
                <x-ui.input label="Daily rate (₱)" type="number" step="0.01" min="1" wire:model="rateAmount" />
                <x-ui.input label="Effective from" type="date" wire:model="rateEffectiveFrom" hint="Payroll periods starting on or after this date use the new rate." />
                <x-ui.input label="Note (optional)" wire:model="rateNote" placeholder="e.g. 2027 rate card, Wage Order NCR-28" />
            </form>
            @if ($history->isNotEmpty())
                <p class="ui-section-label mt-5">History</p>
                <ul class="mt-2 divide-y divide-gray-100 rounded-md border border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($history as $item)
                        <li class="flex items-baseline justify-between gap-3 px-3 py-2">
                            <span>{{ $item->effective_from->format('M j, Y') }} <span class="text-xs text-gray-500">{{ $item->note }} · {{ $item->setter->name ?? '—' }}</span></span>
                            <span class="font-medium tabular-nums">{{ $peso((float) $item->daily_rate) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <x-slot:footer>
                <x-ui.button form="change-rate" wire:loading.attr="disabled">Save rate</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
