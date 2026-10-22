{{--
    "How it's computed (PH law)": the legal basis and rule for each deduction,
    with the values in force today, and a calculator that runs the real
    PayslipCalculator. A reference for Accounting, not legal advice.
--}}
@php
    $r = $this->current;
    $peso = fn (float $amount, int $decimals = 2): string => '₱'.number_format($amount, $decimals);
    $pct = fn (float $fraction): string => rtrim(rtrim(number_format($fraction * 100, 2), '0'), '.').'%';
    $minimum = $this->calculation['rates']->minimumWage ?? null;
@endphp

<div class="space-y-6">
    @if ($r === null)
        <x-ui.alert tone="amber">No formula rules are in force today.</x-ui.alert>
    @else
        <x-ui.card title="The order on every payslip" description="What the law requires, and the order this payroll takes it in.">
            <ol class="list-decimal space-y-1.5 py-4 pr-4 pl-9 text-sm text-gray-700 dark:text-gray-300">
                <li><b>Gross pay</b>: days × daily rate, plus overtime, holiday premium and night differential (Labor Code).</li>
                <li><b>SSS, PhilHealth, Pag-IBIG</b>: the employee's shares. They are <b>monthly</b>: the 1st–15th payslip takes half, and the last payslip of the month takes the rest on the actual month.</li>
                <li><b>Withholding tax</b> on what's left: gross taxable pay <b>minus</b> those three contributions (they are not taxed). Minimum wage earners pay none.</li>
                <li>Then carried-over amounts, late deduction, adjustments and loans. Net pay never goes below ₱0; anything that doesn't fit is carried to the next payslip.</li>
            </ol>
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.card title="SSS" description="Republic Act 11199 (Social Security Act of 2018); 15% rate from January 2025">
                <div class="space-y-2 p-4 text-sm text-gray-700 dark:text-gray-300">
                    <p>Contributions are based on the <b>Monthly Salary Credit (MSC)</b>: the ₱{{ number_format($r->sssMscStep) }} bracket the month's compensation falls in, from {{ $peso($r->sssMscMin, 0) }} to {{ $peso($r->sssMscMax, 0) }}.</p>
                    <p class="rounded-md bg-gray-50 px-3 py-2 font-mono text-xs dark:bg-gray-800/60">Employee {{ $pct($r->sssEmployeeRate) }} × MSC · Employer {{ $pct($r->sssEmployerRate) }} × MSC<br>e.g. ₱15,860 a month → MSC ₱16,000 → employee ₱800, employer ₱1,600</p>
                    <p>The employer also pays <b>EC</b> (Employees' Compensation, PD 626): {{ $peso($r->sssEcLow, 0) }} below MSC {{ $peso($r->sssEcHighFromMsc, 0) }}, {{ $peso($r->sssEcHigh, 0) }} from it. Above MSC ₱20,000 part of the same 15% goes to the member's provident fund (WISP); the total doesn't change.</p>
                </div>
            </x-ui.card>

            <x-ui.card title="PhilHealth" description="Republic Act 11223 (Universal Health Care Act); 5% since 2024">
                <div class="space-y-2 p-4 text-sm text-gray-700 dark:text-gray-300">
                    <p>A premium of <b>{{ $pct($r->philhealthRate) }}</b> of monthly basic salary, shared <b>equally</b> by employee and employer. Salary below {{ $peso($r->philhealthFloor, 0) }} counts as {{ $peso($r->philhealthFloor, 0) }}; above {{ $peso($r->philhealthCeiling, 0) }} counts as {{ $peso($r->philhealthCeiling, 0) }}.</p>
                    <p class="rounded-md bg-gray-50 px-3 py-2 font-mono text-xs dark:bg-gray-800/60">Premium = {{ $pct($r->philhealthRate) }} × basic (within floor and ceiling) · employee ½ · employer ½<br>e.g. ₱15,860 basic → ₱793 premium → employee ₱396.50</p>
                    <p>Only basic pay counts, not overtime or allowances.</p>
                </div>
            </x-ui.card>

            <x-ui.card title="Pag-IBIG (HDMF)" description="Republic Act 9679; HDMF Circular 460 (Maximum Fund Salary ₱10,000 from February 2024)">
                <div class="space-y-2 p-4 text-sm text-gray-700 dark:text-gray-300">
                    <p>Employee <b>{{ $pct($r->pagibigEmployeeRate) }}</b> ({{ $pct($r->pagibigEmployeeRateLow) }} if the monthly salary is {{ $peso($r->pagibigLowThreshold, 0) }} or less), employer <b>{{ $pct($r->pagibigEmployerRate) }}</b>, counted on salary up to {{ $peso($r->pagibigMaxFundSalary, 0) }}.</p>
                    <p class="rounded-md bg-gray-50 px-3 py-2 font-mono text-xs dark:bg-gray-800/60">Employee {{ $pct($r->pagibigEmployeeRate) }} × min(salary, {{ $peso($r->pagibigMaxFundSalary, 0) }}) = at most {{ $peso($r->pagibigMaxFundSalary * $r->pagibigEmployeeRate, 0) }} a month</p>
                </div>
            </x-ui.card>

            <x-ui.card title="Withholding tax" description="NIRC Sec. 24(A) as amended by RA 10963 (TRAIN), rates from 2023; withholding under RR 2-98 as amended by RR 11-2018">
                <div class="space-y-2 p-4 text-sm text-gray-700 dark:text-gray-300">
                    <p>Payroll runs twice a month, so the <b>semi-monthly</b> table is used, on <b>taxable pay minus the employee's SSS, PhilHealth and Pag-IBIG</b>.</p>
                    <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-800">
                        <table class="ui-table text-xs">
                            <thead><tr><th class="ui-th">Taxable pay per cutoff</th><th class="ui-th">Tax</th></tr></thead>
                            <tbody>
                                @foreach ($r->withholdingSemiMonthly as $i => $bracket)
                                    @php $next = $r->withholdingSemiMonthly[$i + 1]['over'] ?? null; @endphp
                                    <tr>
                                        <td class="ui-td whitespace-nowrap">{{ match (true) { $i === 0 && $next !== null => 'Up to '.$peso($next, 0), $next !== null => 'Over '.$peso($bracket['over'], 0).' up to '.$peso($next, 0), default => 'Over '.$peso($bracket['over'], 0) } }}</td>
                                        <td class="ui-td">{{ $bracket['rate'] <= 0 ? 'None' : $peso($bracket['base']).' + '.$pct($bracket['rate']).' of the excess over '.$peso($bracket['over'], 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p><b>Minimum wage earners</b> (RA 9504, kept by RA 10963): someone paid the statutory minimum wage pays no tax on basic pay, overtime, holiday pay, night differential and hazard pay. Here, anyone whose daily rate is at or below the minimum wage in force{{ $minimum ? ' ('.$peso($minimum, 0).' a day)' : '' }} is treated as one. Paid even ₱1 more, the table applies.</p>
                </div>
            </x-ui.card>
        </div>

        {{-- Calculator --}}
        <x-ui.card title="Try it: one month, two payslips" description="Runs the same calculator as payroll. Rules and minimum wage are the ones in force on the date you pick.">
            <div class="grid grid-cols-2 gap-3 border-b border-gray-100 p-4 sm:grid-cols-4 dark:border-gray-800">
                <x-ui.input label="Daily rate (₱)" type="number" step="0.01" min="1" wire:model.live.debounce.400ms="calcDailyRate" />
                <x-ui.input label="Days worked in the month" type="number" min="1" max="31" wire:model.live.debounce.400ms="calcDays" />
                <x-ui.input label="Other taxable pay in the month (₱)" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="calcOtherPay" hint="Overtime, holiday premium…" />
                <x-ui.input label="Rules as of" type="date" wire:model.live="calcDate" />
            </div>

            @if ($calc = $this->calculation)
                @php
                    [$first, $second] = [$calc['first'], $calc['second']];
                    $rows = [
                        ['Gross pay', fn ($p) => $p->grossPay(), false],
                        ['SSS (employee)', fn ($p) => $p->sss, true],
                        ['PhilHealth (employee)', fn ($p) => $p->philhealth, true],
                        ['Pag-IBIG (employee)', fn ($p) => $p->pagibig, true],
                        ['Withholding tax', fn ($p) => $p->withholdingTax, true],
                        ['Net pay', fn ($p) => $p->netPay(), false],
                    ];
                    $employer = fn ($p) => $p->contributions === [] ? 0 : $p->contributions['sss']['employer'] + $p->contributions['sss']['ec'] + $p->contributions['philhealth']['employer'] + $p->contributions['pagibig']['employer'];
                @endphp
                <div class="space-y-4 p-4">
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ $first->daysWorked }} + {{ $second->daysWorked }} days at {{ $peso((float) $calcDailyRate) }}:
                        @if ($first->isMinimumWageEarner())
                            <x-ui.badge tone="emerald">Minimum wage earner · no tax</x-ui.badge>
                        @else
                            <x-ui.badge tone="blue">Above the minimum wage · BIR table applies</x-ui.badge>
                        @endif
                    </p>
                    <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-800">
                        <table class="ui-table">
                            <thead><tr><th class="ui-th"></th><th class="ui-th text-right">1st–15th</th><th class="ui-th text-right">16th–end</th><th class="ui-th text-right">Month</th></tr></thead>
                            <tbody>
                                @foreach ($rows as [$label, $pick, $deduction])
                                    <tr @class(['font-semibold' => ! $deduction])>
                                        <td class="ui-td">{{ $label }}</td>
                                        @foreach ([$pick($first), $pick($second), $pick($first) + $pick($second)] as $amount)
                                            <td class="ui-td text-right tabular-nums">{{ $deduction ? '−' : '' }}{{ $peso($amount) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                                <tr class="text-gray-500">
                                    <td class="ui-td">Employer shares (SSS + EC, PhilHealth, Pag-IBIG)</td>
                                    <td class="ui-td text-right tabular-nums">{{ $peso($employer($first)) }}</td>
                                    <td class="ui-td text-right tabular-nums">{{ $peso($employer($second)) }}</td>
                                    <td class="ui-td text-right tabular-nums">{{ $peso($employer($first) + $employer($second)) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div><p class="ui-section-label">1st–15th payslip</p><x-payroll.computation :payslip="$first" /></div>
                        <div><p class="ui-section-label">16th–end payslip</p><x-payroll.computation :payslip="$second" /></div>
                    </div>
                </div>
            @else
                <p class="p-4 text-sm text-gray-500">Enter a daily rate and 1–31 days to see the result.</p>
            @endif
        </x-ui.card>

        <x-ui.card title="Not computed by this payroll yet" description="So nobody assumes it is.">
            <ul class="list-disc space-y-1 py-4 pr-4 pl-9 text-sm text-gray-600 dark:text-gray-300">
                <li><b>13th month pay</b> (PD 851) and the <b>₱90,000</b> tax-exempt ceiling for it and other benefits (TRAIN).</li>
                <li><b>De minimis benefits</b> and their limits (RR 11-2018).</li>
                <li><b>Year-end tax adjustment</b> (annualization in December) and BIR Form 2316.</li>
                <li>Night differential is 10% for hours worked 10 PM–6 AM inside the shift (Labor Code Art. 86); the extra night premium on <i>overtime</i> hours isn't added yet. Hazard pay isn't modelled.</li>
            </ul>
            <p class="px-4 pb-4 text-xs text-gray-400">A reference for Accounting, not legal advice. Check the current SSS, PhilHealth, HDMF and BIR circulars before changing rules.</p>
        </x-ui.card>
    @endif
</div>
