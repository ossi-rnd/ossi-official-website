@php
    $run = $this->selectedRun;
    $ceiling = \App\Livewire\Admin\ThirteenthMonth::EXEMPT_CEILING;
@endphp

<div class="space-y-6">
    <x-ui.alert tone="blue" class="text-sm">
        <b>PD 851:</b> every rank-and-file employee who worked at least a month in the year gets <b>1/12 of the basic pay earned in the calendar year</b>, paid <b>no later than December 24</b>. Basic pay here is days worked × daily rate, minus late deductions. Overtime, holiday premium, night differential and adjustments don't count. Employees who left during the year get theirs prorated, the same way. Up to <b>₱{{ number_format($ceiling) }}</b> of 13th month pay and other benefits is tax-exempt (RA 10963).
    </x-ui.alert>

    @error('run') <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror

    @if ($run === null)
        <x-ui.card title="13th month runs" description="Computed from generated payslips. If December's last cutoff isn't generated yet when you pay, run a balance later: it pays only what's still owed.">
            <x-slot:actions>
                <x-ui.button type="button" size="sm" wire:click="startCreate"><x-icon name="plus" class="h-4 w-4" /> New 13th month run</x-ui.button>
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Year</th><th class="ui-th">Covers payslips up to</th><th class="ui-th">Pay date</th><th class="ui-th text-right">Employees</th><th class="ui-th text-right">Total</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->runs as $item)
                            <tr wire:key="tm-{{ $item->id }}">
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $item->year }}</td>
                                <td class="ui-td">{{ $item->covers_until->format('M j, Y') }}</td>
                                <td class="ui-td">{{ $item->pay_date->format('M j, Y') }}</td>
                                <td class="ui-td text-right tabular-nums">{{ $item->payouts_count }}</td>
                                <td class="ui-td text-right"><x-money :amount="$item->payouts_sum_amount ?? 0" /></td>
                                <td class="ui-td"><x-ui.status-badge :status="$item->status" /></td>
                                <td class="ui-td text-right">
                                    <button type="button" wire:click="viewRun({{ $item->id }})" class="inline-flex items-center gap-1 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Review <x-icon name="chevron-right" class="h-3.5 w-3.5" /></button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="ui-td py-8 text-center">No 13th month runs yet. Create one in December, once the first December cutoff is generated.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @else
        @php
            $payouts = $this->payouts;
            $status = $run->status;
            $lateDeadline = $run->pay_date->greaterThan(\Carbon\CarbonImmutable::parse($run->year.'-12-24'));
        @endphp
        <button type="button" wire:click="backToList" class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-100">
            <x-icon name="chevron-left" class="h-4 w-4" /> All 13th month runs
        </button>

        @if ($lateDeadline && ! $status->isFinal())
            <x-ui.alert tone="amber">The pay date is after December 24, the legal deadline. That's fine for a balance run; the main payout must be paid by the 24th.</x-ui.alert>
        @endif

        <x-ui.card :title="$run->year.' 13th month pay'" :description="'Payslips of periods ending up to '.$run->covers_until->format('M j, Y').' · pay date '.$run->pay_date->format('M j, Y').($run->computed_at ? ' · computed '.$run->computed_at->format('M j, g:i A') : '')">
            <x-slot:actions>
                <x-ui.status-badge :status="$status" />
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="exportCsv"><x-icon name="download" class="h-4 w-4" /> CSV</x-ui.button>
                @if ($status === \App\Enums\ThirteenthMonthStatus::Draft)
                    <x-ui.button type="button" variant="danger-outline" size="sm" wire:click="deleteRun" wire:confirm="Delete this draft?">Delete</x-ui.button>
                    <x-ui.button type="button" variant="secondary" size="sm" wire:click="recompute" wire:loading.attr="disabled" wire:target="recompute">Recompute</x-ui.button>
                    <x-ui.button type="button" size="sm" wire:click="advance" wire:confirm="Approve? The amounts become final and employees see them on My Payslips." tooltip-align="end"
                        :disabled-reason="$payouts->isEmpty() ? 'No generated payslips in this period yet.' : null">Approve</x-ui.button>
                @elseif ($status === \App\Enums\ThirteenthMonthStatus::Approved)
                    <x-ui.button type="button" size="sm" wire:click="advance" wire:confirm="Mark every 13th month payout in this run as paid?">Mark as paid</x-ui.button>
                @endif
            </x-slot:actions>

            <div class="grid grid-cols-2 gap-4 border-b border-gray-100 p-4 sm:grid-cols-4 dark:border-gray-800">
                <x-ui.stat label="Employees" :value="$payouts->count()" />
                <x-ui.stat label="Basic pay counted" :value="'₱'.number_format((float) $payouts->sum('basic_pay'), 2)" />
                <x-ui.stat label="Already paid this year" :value="'₱'.number_format((float) $payouts->sum('earlier_paid'), 2)" />
                <x-ui.stat label="To pay now" :value="'₱'.number_format((float) $payouts->sum('amount'), 2)" />
            </div>

            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th class="ui-th">Employee</th>
                            <th class="ui-th text-right">Days</th>
                            <th class="ui-th text-right">Basic pay</th>
                            <th class="ui-th text-right">Late</th>
                            <th class="ui-th text-right">Earned (÷12)</th>
                            <th class="ui-th text-right">Earlier paid</th>
                            <th class="ui-th text-right">To pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($payouts as $payout)
                            <tr wire:key="payout-{{ $payout->id }}">
                                <td class="ui-td">
                                    <details class="group">
                                        <summary class="inline-flex cursor-pointer list-none items-center gap-1 font-medium text-gray-800 dark:text-gray-100 [&::-webkit-details-marker]:hidden">
                                            {{ $payout->user->name }}
                                            <x-icon name="chevron-down" class="h-3.5 w-3.5 text-gray-400 transition-transform group-open:rotate-180" />
                                        </summary>
                                        <ul class="mt-1.5 space-y-0.5 text-xs text-gray-500">
                                            @foreach ($payout->cutoffs as $cutoff)
                                                <li>{{ $cutoff['period'] }}: ₱{{ number_format((float) $cutoff['basic_pay'], 2) }}@if ((float) $cutoff['late_deduction'] > 0) − ₱{{ number_format((float) $cutoff['late_deduction'], 2) }} late @endif</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                    <span class="text-xs text-gray-500">{{ $payout->user->employeeProfile?->employee_number }}</span>
                                    @if ((float) $payout->earned > $ceiling)
                                        <x-ui.badge tone="amber">Over ₱{{ number_format($ceiling) }}: excess taxable</x-ui.badge>
                                    @endif
                                </td>
                                <td class="ui-td text-right tabular-nums">{{ $payout->days_worked }}</td>
                                <td class="ui-td text-right"><x-money :amount="$payout->basic_pay" /></td>
                                <td class="ui-td text-right">@if ((float) $payout->late_deductions > 0)<x-money :amount="$payout->late_deductions" negative />@else — @endif</td>
                                <td class="ui-td text-right"><x-money :amount="$payout->earned" /></td>
                                <td class="ui-td text-right">@if ((float) $payout->earlier_paid > 0)<x-money :amount="$payout->earlier_paid" negative />@else — @endif</td>
                                <td class="ui-td text-right font-semibold text-gray-800 dark:text-gray-100"><x-money :amount="$payout->amount" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="ui-td py-8 text-center">No generated payslips in {{ $run->year }} up to {{ $run->covers_until->format('M j') }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    @if ($creating)
        <x-ui.modal title="New 13th month run" close="$set('creating', false)">
            <form id="thirteenth-month" wire:submit="createRun" class="space-y-4">
                @error('year') <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror
                <x-ui.input label="Year" type="number" min="2000" max="2100" wire:model="year" />
                <x-ui.input label="Count payslips of periods ending up to" type="date" wire:model="coversUntil" hint="Defaults to the last generated cutoff. Only generated payslips count." />
                <x-ui.input label="Pay date" type="date" wire:model="payDate" hint="No later than December 24." />
            </form>
            <x-slot:footer>
                <x-ui.button form="thirteenth-month" wire:loading.attr="disabled" wire:target="createRun">Compute</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
