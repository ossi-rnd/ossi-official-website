@php
    $inForce = $this->current;
    $peso = fn (float $amount, int $decimals = 0): string => '₱'.number_format($amount, $decimals);
    $pct = fn (float $fraction): string => rtrim(rtrim(number_format($fraction * 100, 2), '0'), '.').'%';
    $frozen = $this->frozenUntil;
    $deletable = fn ($date) => $frozen === null || $date->greaterThan($frozen);
@endphp

<div class="space-y-6">
    <x-ui.alert tone="blue" class="text-sm">
        These rules compute every payslip's SSS, PhilHealth, Pag-IBIG and withholding tax. A change is a <b>new version from a date</b>: payroll periods starting on or after it use the new rules, and generated payslips never change.
        @if ($frozen) Payslips up to {{ $frozen->format('M j, Y') }} are generated, so a new version can start from {{ $frozen->addDay()->format('M j, Y') }}. @endif
    </x-ui.alert>

    <div class="flex gap-1 border-b border-gray-200 dark:border-gray-800" role="tablist">
        @foreach (['rules' => 'Rules & versions', 'law' => 'How it\'s computed (PH law)'] as $value => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $value }}')" aria-selected="{{ $tab === $value ? 'true' : 'false' }}"
                @class(['-mb-px border-b-2 px-3 py-2.5 text-sm font-medium', 'border-blue-600 text-blue-600 dark:text-blue-400' => $tab === $value, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $tab !== $value])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'law')
        @include('livewire.admin.partials.tax-law')
    @else
    {{-- In force today --}}
    <x-ui.card title="Rules in force today"
        :description="$this->currentVersion ? 'Version from '.$this->currentVersion->effective_from->format('M j, Y').($this->currentVersion->note ? ' · '.$this->currentVersion->note : '') : 'Built-in rules (config/payroll.php). Save a version to manage them here.'">
        <x-slot:actions>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="startVersion"><x-icon name="document-plus" class="h-4 w-4" /> New version</x-ui.button>
        </x-slot:actions>

        @if ($inForce === null)
            <p class="p-4 text-sm text-gray-500">No formula rules are in force today (fixed amounts of an older schedule).</p>
        @else
            <div class="grid grid-cols-1 gap-4 p-4 md:grid-cols-3">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                    <p class="ui-section-label">SSS</p>
                    <dl class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Employee / employer</dt><dd>{{ $pct($inForce->sssEmployeeRate) }} / {{ $pct($inForce->sssEmployerRate) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Salary credit (MSC)</dt><dd>{{ $peso($inForce->sssMscMin) }}–{{ $peso($inForce->sssMscMax) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Bracket step</dt><dd>{{ $peso($inForce->sssMscStep) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">EC (employer)</dt><dd>{{ $peso($inForce->sssEcLow) }} / {{ $peso($inForce->sssEcHigh) }} from MSC {{ $peso($inForce->sssEcHighFromMsc) }}</dd></div>
                    </dl>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                    <p class="ui-section-label">PhilHealth</p>
                    <dl class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Premium (split equally)</dt><dd>{{ $pct($inForce->philhealthRate) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Floor / ceiling</dt><dd>{{ $peso($inForce->philhealthFloor) }} / {{ $peso($inForce->philhealthCeiling) }}</dd></div>
                    </dl>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                    <p class="ui-section-label">Pag-IBIG</p>
                    <dl class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Employee / employer</dt><dd>{{ $pct($inForce->pagibigEmployeeRate) }} / {{ $pct($inForce->pagibigEmployerRate) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Employee at ≤ {{ $peso($inForce->pagibigLowThreshold) }}</dt><dd>{{ $pct($inForce->pagibigEmployeeRateLow) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Maximum fund salary</dt><dd>{{ $peso($inForce->pagibigMaxFundSalary) }}</dd></div>
                    </dl>
                </div>
            </div>
            <div class="px-4 pb-4">
                <p class="ui-section-label mb-2">Withholding tax · BIR semi-monthly table</p>
                <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-800">
                    <table class="ui-table">
                        <thead><tr><th class="ui-th">Taxable pay over</th><th class="ui-th text-right">Base tax</th><th class="ui-th text-right">+ rate on the excess</th></tr></thead>
                        <tbody>
                            @foreach ($inForce->withholdingSemiMonthly as $bracket)
                                <tr><td class="ui-td tabular-nums">{{ $peso($bracket['over']) }}</td><td class="ui-td text-right tabular-nums">{{ $peso($bracket['base'], 2) }}</td><td class="ui-td text-right">{{ $pct($bracket['rate']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </x-ui.card>

    {{-- Versions --}}
    <x-ui.card title="Saved versions" description="Newest first. A version that no generated payslip used yet can be deleted.">
        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">From</th><th class="ui-th">Note</th><th class="ui-th">Saved by</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->versions as $version)
                        <tr wire:key="version-{{ $version->id }}">
                            <td class="ui-td whitespace-nowrap">
                                {{ $version->effective_from->format('M j, Y') }}
                                @if ($this->currentVersion?->is($version))<x-ui.badge tone="emerald" class="ml-1">In force</x-ui.badge>@elseif ($version->effective_from->isFuture())<x-ui.badge tone="blue" class="ml-1">Upcoming</x-ui.badge>@endif
                            </td>
                            <td class="ui-td">{{ $version->note ?? '—' }}</td>
                            <td class="ui-td">{{ $version->creator->name ?? '—' }} · {{ $version->updated_at->format('M j, Y') }}</td>
                            <td class="ui-td text-right">
                                @if ($deletable($version->effective_from))
                                    <button type="button" wire:click="deleteVersion({{ $version->id }})" wire:confirm="Delete this version?" aria-label="Delete version" class="rounded p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400"><x-icon name="trash" class="h-4 w-4" /></button>
                                @else
                                    <x-ui.tooltip text="Generated payslips used this version, so it stays." align="end"><x-icon name="lock" class="h-4 w-4 text-gray-300" /></x-ui.tooltip>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-td py-6 text-center">None yet: the built-in rules apply.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- Minimum wage --}}
    <x-ui.card title="Minimum wage" description="Daily minimum (NCR, non-agriculture) by the date each wage order took effect. At or below it = minimum wage earner, no withholding tax.">
        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">From</th><th class="ui-th text-right">Daily minimum</th><th class="ui-th">Wage order</th><th class="ui-th">Source</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @foreach ($this->minimumWages as $from => $step)
                        @php $date = \Carbon\CarbonImmutable::parse($from); @endphp
                        <tr wire:key="wage-{{ $from }}">
                            <td class="ui-td whitespace-nowrap">{{ $date->format('M j, Y') }}</td>
                            <td class="ui-td text-right font-medium tabular-nums">{{ $peso($step['amount'], 2) }}</td>
                            <td class="ui-td">{{ $step['wage_order'] ?? '—' }}</td>
                            <td class="ui-td"><x-ui.badge :tone="$step['saved'] ? 'blue' : 'gray'">{{ $step['saved'] ? 'Saved here' : 'Built-in' }}</x-ui.badge></td>
                            <td class="ui-td text-right">
                                @if ($step['saved'] && $deletable($date))
                                    <button type="button" wire:click="deleteWage('{{ $from }}')" wire:confirm="Delete this minimum wage?" aria-label="Delete minimum wage" class="rounded p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400"><x-icon name="trash" class="h-4 w-4" /></button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <form wire:submit="saveWage" class="grid grid-cols-1 gap-3 border-t border-gray-100 p-4 sm:grid-cols-4 sm:items-start dark:border-gray-800">
            {{-- Top-aligned so an error under one field doesn't push the others down; the button skips the label row (20px + 4px gap). --}}
            <x-ui.input label="From" type="date" wire:model="wageFrom" />
            <x-ui.input label="Daily minimum (₱)" type="number" step="0.01" min="1" wire:model="wageAmount" />
            <x-ui.input label="Wage order" wire:model="wageOrder" placeholder="e.g. NCR-29" />
            <x-ui.button variant="secondary" class="sm:mt-6">Add minimum wage</x-ui.button>
        </form>
    </x-ui.card>

    @endif

    @if ($editing)
        <x-ui.modal title="New version of the rules" close="$set('editing', false)" max-width="2xl">
            <form id="statutory-version" wire:submit="saveVersion" class="space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input label="Effective from" type="date" wire:model="effectiveFrom" hint="Payroll periods starting on or after this date use these rules." />
                    <x-ui.input label="Note (circular, reason)" wire:model="note" placeholder="e.g. SSS Circular 2027-001" />
                </div>

                <fieldset>
                    <legend class="ui-section-label">SSS</legend>
                    <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <x-ui.input label="Employee %" type="number" step="0.01" min="0" wire:model="rules.sss.employee_rate" />
                        <x-ui.input label="Employer %" type="number" step="0.01" min="0" wire:model="rules.sss.employer_rate" />
                        <x-ui.input label="MSC minimum ₱" type="number" min="0" wire:model="rules.sss.msc_min" />
                        <x-ui.input label="MSC maximum ₱" type="number" min="0" wire:model="rules.sss.msc_max" />
                        <x-ui.input label="Bracket step ₱" type="number" min="0" wire:model="rules.sss.msc_step" />
                        <x-ui.input label="EC below ₱" type="number" min="0" wire:model="rules.sss.ec_low" />
                        <x-ui.input label="EC from ₱" type="number" min="0" wire:model="rules.sss.ec_high" />
                        <x-ui.input label="EC higher from MSC ₱" type="number" min="0" wire:model="rules.sss.ec_high_from_msc" />
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="ui-section-label">PhilHealth</legend>
                    <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <x-ui.input label="Premium % (split)" type="number" step="0.01" min="0" wire:model="rules.philhealth.rate" />
                        <x-ui.input label="Floor ₱" type="number" min="0" wire:model="rules.philhealth.floor" />
                        <x-ui.input label="Ceiling ₱" type="number" min="0" wire:model="rules.philhealth.ceiling" />
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="ui-section-label">Pag-IBIG</legend>
                    <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        <x-ui.input label="Employee %" type="number" step="0.01" min="0" wire:model="rules.pagibig.employee_rate" />
                        <x-ui.input label="Low-pay %" type="number" step="0.01" min="0" wire:model="rules.pagibig.employee_rate_low" />
                        <x-ui.input label="Low pay up to ₱" type="number" min="0" wire:model="rules.pagibig.low_threshold" />
                        <x-ui.input label="Employer %" type="number" step="0.01" min="0" wire:model="rules.pagibig.employer_rate" />
                        <x-ui.input label="Max fund salary ₱" type="number" min="0" wire:model="rules.pagibig.max_fund_salary" />
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="ui-section-label">Withholding tax · semi-monthly table</legend>
                    @error('rules.withholding_semi_monthly')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    <div class="mt-2 space-y-2">
                        @foreach ($this->rules['withholding_semi_monthly'] as $index => $bracket)
                            <div wire:key="bracket-{{ $index }}" class="grid grid-cols-[1fr_1fr_1fr_auto] items-end gap-2">
                                <x-ui.input :label="$index === 0 ? 'Over ₱' : null" type="number" step="0.01" min="0" wire:model="rules.withholding_semi_monthly.{{ $index }}.over" />
                                <x-ui.input :label="$index === 0 ? 'Base tax ₱' : null" type="number" step="0.01" min="0" wire:model="rules.withholding_semi_monthly.{{ $index }}.base" />
                                <x-ui.input :label="$index === 0 ? '+ % of excess' : null" type="number" step="0.01" min="0" wire:model="rules.withholding_semi_monthly.{{ $index }}.rate" />
                                <button type="button" wire:click="removeBracket({{ $index }})" aria-label="Remove bracket" class="mb-1 rounded p-2 text-gray-400 hover:text-red-600 dark:hover:text-red-400"><x-icon name="trash" class="h-4 w-4" /></button>
                            </div>
                        @endforeach
                        <button type="button" wire:click="addBracket" class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300"><x-icon name="plus" class="h-4 w-4" /> Add bracket</button>
                    </div>
                </fieldset>
            </form>
            <x-slot:footer>
                <x-ui.button form="statutory-version" wire:loading.attr="disabled" wire:target="saveVersion">Save version</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
