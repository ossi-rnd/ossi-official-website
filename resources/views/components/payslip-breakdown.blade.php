@props(['payslip', 'attendance' => [], 'lateDays' => []])

{{--
    One payslip, line by line. Used by BOTH "My Payslips" and the payroll
    modal, fed by the same ResolvePayslips query: same object, same markup.
--}}
@php
    /** @var \App\Data\PayslipBreakdown $payslip */
    // No attendance and nothing generated = no payslip at all. Showing the fixed
    // contributions against ₱0 would print a negative "net pay" for money nobody
    // owes or is owed, so the figures are left out entirely.
    $noPayslip = ! $payslip->isGenerated && $payslip->daysWorked === 0;
@endphp

<x-sensitive :attributes="$attributes">
    @if ($noPayslip)
        <x-ui.alert tone="amber" class="mb-4 text-xs">No attendance in this period, so no payslip will be generated.</x-ui.alert>
    @elseif (! $payslip->isGenerated)
        <x-ui.alert tone="blue" class="mb-4 text-xs">
            Estimate based on attendance so far. Figures are final once payroll generates this payslip.
        </x-ui.alert>
    @endif

    @if ($attendance !== [])
        <div class="mb-4 grid grid-cols-4 gap-2 text-center">
            @foreach ([['Present', $attendance['present'], 'text-emerald-600 dark:text-emerald-400'], ['Absent', $attendance['absent'], 'text-red-600 dark:text-red-400'], ['Leave', $attendance['on_leave'], 'text-indigo-600 dark:text-indigo-400'], ['Late', $attendance['late'], 'text-amber-600 dark:text-amber-400']] as [$label, $count, $color])
                <div class="rounded-md border border-gray-200 p-2 dark:border-gray-800">
                    <p class="text-xs text-gray-500">{{ $label }}</p>
                    <p class="text-lg font-semibold {{ $color }}">{{ $count }}</p>
                </div>
            @endforeach
        </div>
    @endif

    @if ($noPayslip)
        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-6 text-center dark:border-gray-700">
            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">No earnings or deductions for this period</p>
            <p class="mt-1 text-xs text-gray-500">Contributions and loan installments are only taken from a generated payslip. Nothing is owed for a period without work.</p>
        </div>
    @else
    <p class="ui-section-label mb-1">Earnings</p>
    <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-gray-600 dark:text-gray-300">Basic pay ({{ $payslip->daysWorked }} {{ str('day')->plural($payslip->daysWorked) }} × <span class="tabular-nums">₱{{ number_format($payslip->dailyRate, 2) }}</span>)</dt><dd><x-money :amount="$payslip->basicPay" /></dd></div>
        @if ($payslip->nightDifferential > 0)
            <div class="flex justify-between gap-4"><dt class="text-gray-600 dark:text-gray-300">Night differential (10%{{ $payslip->nightMinutes > 0 ? ' · '.rtrim(rtrim(number_format($payslip->nightMinutes / 60, 1), '0'), '.').' hrs, 10 PM–6 AM' : '' }})</dt><dd><x-money :amount="$payslip->nightDifferential" /></dd></div>
        @endif
        @if ($payslip->overtimePay > 0)
            <div class="flex justify-between gap-4"><dt class="text-gray-600 dark:text-gray-300">Overtime ({{ intdiv($payslip->overtimeMinutes, 60) }}h {{ $payslip->overtimeMinutes % 60 }}m approved × 125%)</dt><dd><x-money :amount="$payslip->overtimePay" /></dd></div>
        @endif
        @if ($payslip->holidayPay > 0)
            <div class="flex justify-between gap-4"><dt class="text-gray-600 dark:text-gray-300">Holiday premium</dt><dd><x-money :amount="$payslip->holidayPay" /></dd></div>
        @endif
        @foreach ($payslip->earningAdjustments() as $adjustment)
            <div class="flex justify-between gap-4">
                <dt class="text-gray-600 dark:text-gray-300">{{ $adjustment['label'] }} <x-ui.badge tone="blue" class="ml-1">Adjustment</x-ui.badge></dt>
                <dd><x-money :amount="$adjustment['amount']" /></dd>
            </div>
        @endforeach
        <div class="flex justify-between gap-4 border-t border-gray-100 pt-1.5 font-medium dark:border-gray-800"><dt>Gross pay</dt><dd><x-money :amount="$payslip->grossPay()" /></dd></div>
    </dl>

    <p class="ui-section-label mt-5 mb-1">Deductions</p>
    <dl class="space-y-1.5 text-sm">
        @php
            // 2026+: say how each contribution was worked out, in one short line.
            $c = $payslip->contributions;
            $when = $c === [] ? null : ($c['final_cutoff'] ? 'balance of the month' : 'half of the month');
        @endphp
        @foreach ([['SSS', $payslip->sss, $c === [] ? null : 'MSC ₱'.number_format($c['msc']).' · '.$when], ['PhilHealth', $payslip->philhealth, $when ? '2.5% of basic pay · '.$when : null], ['Pag-IBIG', $payslip->pagibig, $when ? '2%, up to ₱200 a month · '.$when : null]] as [$agency, $amount, $how])
            <div class="flex justify-between gap-4">
                <dt class="text-gray-600 dark:text-gray-300">{{ $agency }} @if ($how)<span class="block text-xs text-gray-400">{{ $how }}</span>@endif</dt>
                <dd><x-money :amount="$amount" negative /></dd>
            </div>
        @endforeach
        <div class="flex justify-between gap-4">
            <dt class="text-gray-600 dark:text-gray-300">
                Withholding tax
                @if ($payslip->isMinimumWageEarner())
                    <span class="text-gray-400">(minimum wage earner, exempt)</span>
                @elseif ($c !== [])
                    <span class="block text-xs text-gray-400">BIR semi-monthly table, after SSS, PhilHealth and Pag-IBIG</span>
                @endif
            </dt>
            <dd><x-money :amount="$payslip->withholdingTax" negative /></dd>
        </div>
        @foreach ($payslip->carryoverLines as $carried)
            <div class="flex justify-between gap-4">
                <dt class="text-gray-600 dark:text-gray-300">{{ $carried['label'] }} <x-ui.badge tone="amber" class="ml-1">Carried over</x-ui.badge></dt>
                <dd><x-money :amount="$carried['amount']" negative /></dd>
            </div>
        @endforeach
        @if ($payslip->lateMinutes > 0)
            {{-- Full details on purpose: the employee sees every late day behind the amount, not just a total. --}}
            <div x-data="{ open: false }">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-600 dark:text-gray-300">
                        Late ({{ \App\Support\Duration::minutes($payslip->lateMinutes) }}{{ $lateDays !== [] ? ', '.count($lateDays).' '.str('day')->plural(count($lateDays)) : '' }})
                        @if ($lateDays !== [])
                            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" class="ml-1 text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                                <span x-show="! open">Show days</span><span x-show="open" x-cloak>Hide days</span>
                            </button>
                        @endif
                    </dt>
                    <dd class="text-red-600 dark:text-red-400"><x-money :amount="$payslip->lateDeduction" negative /></dd>
                </div>
                @if ($lateDays !== [])
                    <div x-show="open" x-cloak class="mt-2 overflow-x-auto rounded-md border border-gray-200 dark:border-gray-800">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 text-left text-gray-500 dark:bg-gray-800/50 dark:text-gray-400">
                                <tr><th class="px-3 py-1.5 font-medium">Date</th><th class="px-3 py-1.5 font-medium">Shift start</th><th class="px-3 py-1.5 font-medium">Time in</th><th class="px-3 py-1.5 text-right font-medium">Late</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($lateDays as $day)
                                    <tr>
                                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $day->date->format('D, M j') }}</td>
                                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $day->shiftStart ?? '—' }}@if ($day->graceMinutes > 0)<span class="text-gray-400"> + {{ $day->graceMinutes }} min grace</span>@endif</td>
                                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $day->clockInAt?->format('g:i A') ?? '—' }}</td>
                                        <td class="px-3 py-1.5 text-right whitespace-nowrap text-amber-600 dark:text-amber-400">{{ \App\Support\Duration::minutes($day->lateMinutes) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="border-t border-gray-100 px-3 py-1.5 text-xs text-gray-500 dark:border-gray-800">
                            The deduction is exactly these {{ $payslip->lateMinutes }} minutes (after the grace period) at the hourly rate: the daily rate ÷ 8.
                        </p>
                    </div>
                @endif
            </div>
        @endif
        @foreach ($payslip->deductionAdjustments() as $adjustment)
            <div class="flex justify-between gap-4">
                <dt class="text-gray-600 dark:text-gray-300">{{ $adjustment['label'] }} <x-ui.badge tone="blue" class="ml-1">Adjustment</x-ui.badge></dt>
                <dd><x-money :amount="abs($adjustment['amount'])" negative /></dd>
            </div>
        @endforeach
        @foreach ($payslip->loanLines as $loan)
            <div class="flex justify-between gap-4">
                <dt class="text-gray-600 dark:text-gray-300">
                    {{ $loan['label'] }} <x-ui.badge tone="blue" class="ml-1">{{ \App\Enums\DeductionType::tryFrom($loan['type'])?->label() ?? $loan['type'] }}</x-ui.badge>
                    @if ($loan['remaining_balance'] !== null)
                        <span class="block text-xs text-gray-400">Balance before this payslip: <x-money :amount="$loan['remaining_balance']" /></span>
                    @endif
                </dt>
                <dd><x-money :amount="$loan['amount']" negative /></dd>
            </div>
        @endforeach
        <div class="flex justify-between gap-4 border-t border-gray-100 pt-1.5 font-medium dark:border-gray-800"><dt>Total deductions</dt><dd><x-money :amount="$payslip->totalDeductions()" negative /></dd></div>
    </dl>

    <x-payroll.computation :payslip="$payslip" />

    @if ($payslip->shortfallLines !== [])
        {{-- Net pay stops at ₱0. Say plainly what wasn't taken and where it goes. --}}
        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100">
            <p class="font-medium">Not deducted: the pay for this period wasn't enough</p>
            <dl class="mt-2 space-y-1">
                @foreach ($payslip->shortfallLines as $shortfall)
                    <div class="flex justify-between gap-4 text-xs">
                        <dt>{{ $shortfall['label'] }} <span class="opacity-75">· {{ $shortfall['carries'] ? 'carried to the next payslip' : 'stays in the loan balance' }}</span></dt>
                        <dd class="font-medium whitespace-nowrap"><x-money :amount="$shortfall['amount']" /></dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    <div class="mt-5 flex justify-between rounded-lg bg-blue-50 px-4 py-3 text-sm font-semibold text-blue-900 dark:bg-blue-500/10 dark:text-blue-200">
        <span>Net pay</span>
        <x-money :amount="$payslip->netPay()" />
    </div>
    @endif

    <p class="mt-2 text-center text-xs text-gray-400">Rates in force from {{ $payslip->rateSchedule }} · {{ $payslip->isOfficeStaff ? 'Office staff' : 'Field staff' }}</p>
    <p class="mt-1 text-center text-xs text-gray-400">{{ config('payroll.disclaimer') }}</p>
</x-sensitive>
