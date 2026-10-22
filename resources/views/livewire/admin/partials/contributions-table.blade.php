{{--
    "Contributions & tax" for the employees on this page of the run: what each
    one owes SSS, PhilHealth, Pag-IBIG and the BIR this cutoff, employee AND
    employer shares, so Accounting can check the figures before approving.
    The CSV export has the same columns for the whole run.
--}}
@php
    $rows = $this->employees->getCollection()->map(fn ($employee) => [$employee, $this->pagePayslips[$employee->id]]);
    $statutory = $rows->filter(fn ($row) => $row[1]->usesStatutoryFormulas());
    $sum = fn (callable $pick): float => (float) $statutory->sum(fn ($row) => $pick($row[1]));
@endphp

<div class="overflow-x-auto">
    <table class="ui-table">
        <thead>
            <tr>
                <th class="ui-th">Employee</th>
                <th class="ui-th text-right">Daily rate</th>
                <th class="ui-th text-right">Taxable pay</th>
                <th class="ui-th text-right">SSS MSC</th>
                <th class="ui-th text-right">SSS<span class="block font-normal normal-case">EE · ER + EC</span></th>
                <th class="ui-th text-right">PhilHealth<span class="block font-normal normal-case">EE · ER</span></th>
                <th class="ui-th text-right">Pag-IBIG<span class="block font-normal normal-case">EE · ER</span></th>
                <th class="ui-th text-right">Taxable for tax</th>
                <th class="ui-th text-right">Tax</th>
                <th class="ui-th"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as [$employee, $payslip])
                @php $c = $payslip->contributions; @endphp
                <tr wire:key="contrib-{{ $employee->id }}">
                    <td class="ui-td min-w-52">
                        <p class="font-medium text-gray-800 dark:text-gray-100">{{ $employee->name }}</p>
                        <p class="text-xs text-gray-500">
                            @if ($c === [])
                                {{ $payslip->daysWorked === 0 ? 'No attendance' : 'Fixed amounts (pre-2026 schedule)' }}
                            @else
                                {{ $c['final_cutoff'] ? 'Last cutoff: balances the month' : 'First cutoff: half the month' }}
                            @endif
                        </p>
                    </td>
                    <td class="ui-td text-right tabular-nums">@if ($payslip->dailyRate > 0) ₱{{ number_format($payslip->dailyRate, 2) }} @else <span class="text-amber-600 dark:text-amber-400">Not set</span> @endif</td>
                    @if ($c === [])
                        <td class="ui-td text-right text-gray-400" colspan="7">—</td>
                    @else
                        <td class="ui-td text-right tabular-nums">₱{{ number_format($c['sss']['base'], 2) }}</td>
                        <td class="ui-td text-right tabular-nums">₱{{ number_format($c['msc']) }}</td>
                        <td class="ui-td text-right whitespace-nowrap tabular-nums">₱{{ number_format($payslip->sss, 2) }}<span class="block text-xs text-gray-400">₱{{ number_format($c['sss']['employer'] + $c['sss']['ec'], 2) }}</span></td>
                        <td class="ui-td text-right whitespace-nowrap tabular-nums">₱{{ number_format($payslip->philhealth, 2) }}<span class="block text-xs text-gray-400">₱{{ number_format($c['philhealth']['employer'], 2) }}</span></td>
                        <td class="ui-td text-right whitespace-nowrap tabular-nums">₱{{ number_format($payslip->pagibig, 2) }}<span class="block text-xs text-gray-400">₱{{ number_format($c['pagibig']['employer'], 2) }}</span></td>
                        <td class="ui-td text-right tabular-nums">{{ $c['minimum_wage_earner'] ? '—' : '₱'.number_format($c['taxable'], 2) }}</td>
                        <td class="ui-td text-right whitespace-nowrap tabular-nums">
                            @if ($c['minimum_wage_earner'])
                                <x-ui.badge tone="emerald">MWE · exempt</x-ui.badge>
                            @else
                                ₱{{ number_format($payslip->withholdingTax, 2) }}
                            @endif
                        </td>
                    @endif
                    <td class="ui-td text-right">
                        <button type="button" wire:click="viewPayslip({{ $employee->id }})" aria-label="See how {{ $employee->name }}'s contributions were computed" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-50 hover:text-blue-600 dark:hover:text-blue-400"><x-icon name="eye" class="h-4 w-4" /></button>
                    </td>
                </tr>
            @endforeach
        </tbody>
        @if ($statutory->isNotEmpty())
            <tfoot class="bg-gray-50 text-sm font-medium dark:bg-gray-800/50">
                <tr>
                    <td class="ui-td" colspan="4">Total for this page <span class="font-normal text-gray-400">(the CSV export covers the whole run)</span></td>
                    <td class="ui-td text-right whitespace-nowrap tabular-nums">₱{{ number_format($sum(fn ($p) => $p->sss), 2) }}<span class="block text-xs font-normal text-gray-400">₱{{ number_format($sum(fn ($p) => $p->contributions['sss']['employer'] + $p->contributions['sss']['ec']), 2) }}</span></td>
                    <td class="ui-td text-right whitespace-nowrap tabular-nums">₱{{ number_format($sum(fn ($p) => $p->philhealth), 2) }}<span class="block text-xs font-normal text-gray-400">₱{{ number_format($sum(fn ($p) => $p->contributions['philhealth']['employer']), 2) }}</span></td>
                    <td class="ui-td text-right whitespace-nowrap tabular-nums">₱{{ number_format($sum(fn ($p) => $p->pagibig), 2) }}<span class="block text-xs font-normal text-gray-400">₱{{ number_format($sum(fn ($p) => $p->contributions['pagibig']['employer']), 2) }}</span></td>
                    <td class="ui-td"></td>
                    <td class="ui-td text-right tabular-nums">₱{{ number_format($sum(fn ($p) => $p->withholdingTax), 2) }}</td>
                    <td class="ui-td"></td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
