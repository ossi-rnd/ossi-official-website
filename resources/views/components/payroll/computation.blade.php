@props(['payslip'])

{{--
    "How this was computed": the working behind SSS, PhilHealth, Pag-IBIG and
    tax, step by step, from what the payslip SAVED (so a frozen payslip shows
    the rules it was computed with, not today's). 2026+ payslips only.
--}}
@php
    /** @var \App\Data\PayslipBreakdown $payslip */
    $c = $payslip->contributions;
    $peso = fn (float $amount): string => '₱'.number_format($amount, 2);
@endphp

@if ($c !== [])
    @php
        $final = $c['final_cutoff'];
        $cutoff = fn (array $share): string => $final
            ? 'Month total '.$peso($share['monthly']).' − already deducted '.$peso(max(0, $share['monthly'] - $share['employee'])).' = '.$peso($share['employee'])
            : 'Half of '.$peso($share['monthly']).' = '.$peso($share['employee']);
        $bracket = $c['tax_bracket'];
    @endphp

    <details class="group mt-4 rounded-lg border border-gray-200 dark:border-gray-800">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-2.5 text-sm font-medium text-gray-800 dark:text-gray-100">
            How contributions and tax were computed
            <x-icon name="chevron-down" class="h-4 w-4 text-gray-400 transition-transform group-open:rotate-180" />
        </summary>

        <div class="space-y-4 border-t border-gray-100 px-4 py-3 text-xs text-gray-600 dark:border-gray-800 dark:text-gray-300">
            <p class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-800/60">
                @if ($final)
                    <b>Last cutoff of the month.</b> Contributions are monthly, so this payslip takes the month's total (both cutoffs) minus what the first cutoff already took.
                @else
                    <b>First cutoff of the month.</b> Contributions are monthly, so this payslip takes half, estimated as if the month continues like this cutoff (× 2). The month's last payslip balances it.
                @endif
            </p>

            <dl class="space-y-3">
                <div>
                    <dt class="font-semibold text-gray-800 dark:text-gray-100">SSS <span class="font-normal text-gray-400">· RA 11199</span></dt>
                    <dd class="mt-0.5 space-y-0.5">
                        <p>Compensation for the month {{ $peso($c['sss']['basis']) }} → salary credit (MSC) {{ $peso($c['msc']) }}</p>
                        <p>Employee 5% of the MSC = {{ $peso($c['sss']['monthly']) }} a month · {{ $cutoff($c['sss']) }}</p>
                        <p class="text-gray-400">Employer share {{ $peso($c['sss']['employer']) }} + EC {{ $peso($c['sss']['ec']) }} this cutoff (not deducted from pay)</p>
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-800 dark:text-gray-100">PhilHealth <span class="font-normal text-gray-400">· RA 11223</span></dt>
                    <dd class="mt-0.5 space-y-0.5">
                        <p>Basic pay for the month, within the ₱10,000 floor and ₱100,000 ceiling: {{ $peso($c['philhealth']['basis']) }}</p>
                        <p>5% premium, employee half (2.5%) = {{ $peso($c['philhealth']['monthly']) }} a month · {{ $cutoff($c['philhealth']) }}</p>
                        <p class="text-gray-400">Employer share {{ $peso($c['philhealth']['employer']) }} this cutoff</p>
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-800 dark:text-gray-100">Pag-IBIG <span class="font-normal text-gray-400">· HDMF Circular 460</span></dt>
                    <dd class="mt-0.5 space-y-0.5">
                        <p>Pay for the month counted up to ₱10,000: {{ $peso($c['pagibig']['basis']) }}</p>
                        <p>Employee 2% = {{ $peso($c['pagibig']['monthly']) }} a month · {{ $cutoff($c['pagibig']) }}</p>
                        <p class="text-gray-400">Employer share {{ $peso($c['pagibig']['employer']) }} this cutoff</p>
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-800 dark:text-gray-100">Withholding tax <span class="font-normal text-gray-400">· BIR RR 11-2018, semi-monthly table</span></dt>
                    <dd class="mt-0.5 space-y-0.5">
                        @if ($c['minimum_wage_earner'])
                            <p>Daily rate {{ $peso($payslip->dailyRate) }} is at or below the minimum wage: a <b>minimum wage earner</b>. Basic pay, overtime, holiday pay and night differential are exempt, so no tax is withheld.</p>
                        @else
                            <p>Taxable pay {{ $peso($c['sss']['base']) }} − SSS {{ $peso($c['sss']['employee']) }} − PhilHealth {{ $peso($c['philhealth']['employee']) }} − Pag-IBIG {{ $peso($c['pagibig']['employee']) }} = <b>{{ $peso($c['taxable']) }}</b></p>
                            @if ($bracket === null || $bracket['rate'] <= 0)
                                <p>Up to ₱10,417 for the cutoff: 0% → {{ $peso($payslip->withholdingTax) }}</p>
                            @else
                                <p>Bracket over {{ $peso($bracket['over']) }}: {{ $peso($bracket['base']) }} + {{ rtrim(rtrim(number_format($bracket['rate'] * 100, 2), '0'), '.') }}% × ({{ $peso($c['taxable']) }} − {{ $peso($bracket['over']) }}) = <b>{{ $peso($payslip->withholdingTax) }}</b></p>
                            @endif
                        @endif
                    </dd>
                </div>
            </dl>
        </div>
    </details>
@endif
