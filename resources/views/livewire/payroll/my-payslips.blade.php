<div class="mx-auto max-w-3xl space-y-6">
    @if (! $this->onPayroll)
        <div class="ui-card p-6 text-center">
            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">No payroll record</p>
            <p class="mt-1 text-sm text-gray-500">Payslips appear here once HR has added your employee record.</p>
        </div>
    @elseif ($this->selectedRun === null)
        <livewire:payroll.deduction-authorizations />
        <x-ui.card title="My Payslips" description="An estimate appears as soon as a cut-off starts processing; it becomes final once payroll generates it.">
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Pay period</th><th class="ui-th">Pay date</th><th class="ui-th text-right">Net pay</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->runs as $run)
                            @php $payslip = $this->payslips[$run->id] ?? null; @endphp
                            <tr wire:key="run-{{ $run->id }}">
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $run->periodLabel() }}</td>
                                <td class="ui-td">{{ $run->pay_date->format('M j, Y') }}</td>
                                <td class="ui-td text-right">@if ($payslip && $payslip->daysWorked > 0)<x-money :amount="$payslip->netPay()" />@else — @endif</td>
                                <td class="ui-td">
                                    <x-ui.status-badge :status="$run->status" />
                                    @if ($payslip && ! $payslip->isGenerated) <x-ui.badge tone="blue">Estimate</x-ui.badge> @endif
                                </td>
                                <td class="ui-td text-right">
                                    <button type="button" wire:click="viewRun({{ $run->id }})" class="inline-flex items-center gap-1 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">View <x-icon name="chevron-right" class="h-3.5 w-3.5" /></button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="ui-td py-8 text-center">No payroll periods yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        @if ($this->thirteenthMonth->isNotEmpty())
            <x-ui.card title="13th month pay" description="1/12 of your basic pay earned in the year (PD 851). Tax-free up to ₱90,000.">
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th class="ui-th">Year</th><th class="ui-th">Pay date</th><th class="ui-th text-right">Basic pay counted</th><th class="ui-th text-right">Amount</th><th class="ui-th">Status</th></tr></thead>
                        <tbody>
                            @foreach ($this->thirteenthMonth as $payout)
                                <tr wire:key="tm-{{ $payout->id }}">
                                    <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $payout->run->year }} <span class="text-xs font-normal text-gray-500">up to {{ $payout->run->covers_until->format('M j') }}</span></td>
                                    <td class="ui-td">{{ $payout->run->pay_date->format('M j, Y') }}</td>
                                    <td class="ui-td text-right"><x-money :amount="(float) $payout->basic_pay - (float) $payout->late_deductions" /></td>
                                    <td class="ui-td text-right font-semibold"><x-money :amount="$payout->amount" /></td>
                                    <td class="ui-td"><x-ui.status-badge :status="$payout->run->status" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    @else
        <button type="button" wire:click="backToList" class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-100">
            <x-icon name="chevron-left" class="h-4 w-4" /> Back to my payslips
        </button>

        <x-ui.card :title="$this->selectedRun->periodLabel()" :description="'Pay date: '.$this->selectedRun->pay_date->format('M j, Y')">
            <x-slot:actions><x-ui.status-badge :status="$this->selectedRun->status" /></x-slot:actions>

            <x-payslip-breakdown class="p-5" :payslip="$this->payslips[$this->selectedRun->id]" :attendance="$this->attendance" :late-days="$this->lateDays" />
        </x-ui.card>

        {{-- Report a problem on this payslip, and the answers (roadmap 14). --}}
        <livewire:payroll.payslip-concerns :run-id="$this->selectedRun->id" :key="'concerns-'.$this->selectedRun->id" />
    @endif
</div>
