@php $peso = fn (float|string|null $amount): string => '₱'.number_format((float) $amount, 2); @endphp

<div class="mx-auto max-w-3xl space-y-6">
    <x-ui.card title="My Loans" description="Loans, cash advances and other company deductions: how much is left, how you authorized it, and what each payslip took. Accounting adds them; nothing is taken without your authorization or the law.">
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->loans as $loan)
                @php
                    $d = $loan['deduction'];
                    $total = $d->original_amount !== null ? (float) $d->original_amount : null;
                    $left = $d->remaining_balance !== null ? (float) $d->remaining_balance : null;
                    $share = $total !== null && $total > 0 && $left !== null ? (int) round(($total - $left) / $total * 100) : null;
                    [$state, $tone] = match (true) {
                        $d->declined_at !== null => ['You didn\'t agree', 'gray'],
                        $d->awaiting_authorization => ['Waiting for your authorization', 'amber'],
                        ! $d->is_active && $left !== null && $left <= 0 => ['Paid off', 'gray'],
                        ! $d->is_active => ['Paused', 'gray'],
                        default => ['Being deducted', 'blue'],
                    };
                @endphp
                <li wire:key="loan-{{ $d->id }}" class="space-y-3 px-6 py-4" x-data="{ open: false }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $d->label }} <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $d->type->label() }}</span></p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $peso($d->installment_amount) }} per payslip{{ $total !== null ? ' · '.$peso($total).' in all' : '' }}</p>
                        </div>
                        <x-ui.badge :tone="$tone" class="shrink-0">{{ $state }}</x-ui.badge>
                    </div>

                    @if ($share !== null)
                        <div>
                            <div class="flex items-baseline justify-between text-xs text-gray-500 dark:text-gray-400">
                                <span><span class="font-medium text-gray-800 tabular-nums dark:text-gray-100">{{ $peso($left) }}</span> left</span>
                                <span class="tabular-nums">{{ $peso($total - $left) }} paid</span>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" aria-hidden="true"><div class="h-full rounded-full bg-blue-500" style="width: {{ $share }}%"></div></div>
                        </div>
                    @endif

                    {{-- How it may be taken from pay (Art. 113): their words, a signed paper, or the law. --}}
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($d->awaiting_authorization && ! $d->declined_at)
                            Nothing is taken until you authorize it. <a href="{{ route('payslips') }}" wire:navigate class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Review it on My Payslips</a>
                        @elseif ($d->authorization_basis)
                            {{ $d->authorization_basis->labelForEmployee() }}{{ $d->authorized_at ? ', '.$d->authorized_at->format('M j, Y') : '' }}@if ($d->authorization) · <span title="{{ $d->authorization }}">“{{ \Illuminate\Support\Str::limit($d->authorization, 90) }}”</span>@endif
                        @elseif ($d->type->needsAuthorization())
                            Added before in-app authorization.
                        @endif
                    </p>

                    @if ($loan['payments'] !== [])
                        <div>
                            <button type="button" x-on:click="open = ! open" class="inline-flex items-center gap-1 text-xs font-medium text-gray-600 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">
                                {{ count($loan['payments']) }} {{ str('payslip')->plural(count($loan['payments'])) }} · {{ $peso($loan['paid']) }} taken
                                <x-icon name="chevron-down" class="h-3.5 w-3.5 text-gray-400 transition-transform" x-bind:class="open && 'rotate-180'" />
                            </button>
                            <table x-show="open" x-cloak class="mt-2 w-full text-xs">
                                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th class="py-1 font-medium">Payslip</th><th class="py-1 text-right font-medium">Taken</th><th class="py-1 text-right font-medium">Left after</th></tr></thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($loan['payments'] as $payment)
                                        <tr><td class="py-1.5 text-gray-700 dark:text-gray-300">{{ $payment['period'] }}</td><td class="py-1.5 text-right tabular-nums text-gray-800 dark:text-gray-100">{{ $peso($payment['amount']) }}</td><td class="py-1.5 text-right tabular-nums text-gray-500">{{ $payment['balance_after'] !== null ? $peso($payment['balance_after']) : '—' }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-6 py-8 text-center text-sm text-gray-500">No loans or company deductions.</li>
            @endforelse
        </ul>
    </x-ui.card>

    @if ($bond = $this->cashBond)
        {{-- Kept, not owed: refunded with the final pay. --}}
        <x-ui.card title="Cash bond" description="Kept for you, not owed: it's refunded with your final pay, less what you still owe then.">
            <dl class="grid grid-cols-2 gap-4 px-6 py-5 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-gray-500 dark:text-gray-400">Held now</dt><dd class="mt-0.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $peso($bond['balance']) }}</dd></div>
                <div><dt class="text-xs text-gray-500 dark:text-gray-400">Per payslip</dt><dd class="mt-0.5 text-gray-800 tabular-nums dark:text-gray-100">{{ $peso($bond['per_cutoff']) }}</dd></div>
                <div><dt class="text-xs text-gray-500 dark:text-gray-400">From your payslips</dt><dd class="mt-0.5 text-gray-800 tabular-nums dark:text-gray-100">{{ $peso($bond['collected']) }}</dd></div>
            </dl>
        </x-ui.card>
    @endif
</div>
