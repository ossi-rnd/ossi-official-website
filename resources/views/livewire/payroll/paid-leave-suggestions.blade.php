@php $peso = fn (float|string|null $amount): string => '₱'.number_format((float) $amount, 2); @endphp

{{-- The leave the law pays, for Accounting to check and add. Nothing shows when there's none. --}}
<div @class(['hidden' => $this->payments->isEmpty()])>
    @if ($this->payments->isNotEmpty())
        <x-ui.card title="Paid leave this cut-off"
            description="Approved leave the law pays: maternity, paternity, solo parent, VAWC, special leave for women. Check the days (leave out a rest day inside it), then accept to add it to this payslip, or dismiss it with a reason. Payslips wait until it's decided.">
            @error('paidLeave') <x-ui.alert tone="red" class="mx-4 mt-3">{{ $message }}</x-ui.alert> @enderror
            @error('adjustmentAmount') <x-ui.alert tone="red" class="mx-4 mt-3">{{ $message }}</x-ui.alert> @enderror

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($this->payments as $payment)
                    @php $type = $payment->leaveRequest->type; $open = $payment->status === \App\Models\PaidLeavePayment::SUGGESTED; @endphp
                    <li wire:key="paid-leave-{{ $payment->id }}" @class(['space-y-3 px-5 py-4', 'opacity-60' => $payment->status === \App\Models\PaidLeavePayment::DISMISSED])>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 text-sm">
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ $payment->user->name }} <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $type->label() }} ({{ $type->law() }})</span></p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    Leave {{ $payment->leaveRequest->dateRangeLabel() }} · {{ $payment->days }} {{ str('day')->plural($payment->days) }} in this cut-off{{ $type->maxDays() ? ' (the law gives up to '.$type->maxDays().')' : '' }}
                                </p>
                                @if ($payment->status === \App\Models\PaidLeavePayment::DISMISSED)
                                    <p class="mt-0.5 text-xs text-gray-400">Dismissed: {{ $payment->dismiss_reason }}</p>
                                @endif
                            </div>
                            <p class="text-sm font-medium text-gray-800 tabular-nums dark:text-gray-100">
                                @if ($payment->amount !== null) {{ $peso($payment->amount) }} @if ($payment->daily_rate)<span class="block text-right text-xs font-normal text-gray-500">at {{ $peso($payment->daily_rate) }} a day</span>@endif
                                @else <span class="text-xs font-normal text-gray-500">SSS benefit: enter it</span>
                                @endif
                            </p>
                        </div>

                        @if ($open)
                            <div class="flex flex-wrap items-end justify-end gap-2">
                                @if ($type->paidAsSssBenefit())
                                    <div class="w-44"><x-ui.input :id="'sss-'.$payment->id" label="SSS benefit to advance (₱)" type="number" step="0.01" min="1" wire:model="sssAmount.{{ $payment->id }}" /></div>
                                @else
                                    <div class="w-28"><x-ui.input :id="'days-'.$payment->id" label="Days to pay" type="number" min="1" :max="$payment->days" wire:model="days.{{ $payment->id }}" :placeholder="(string) $payment->days" /></div>
                                @endif
                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="startDismiss({{ $payment->id }})">Dismiss</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="accept({{ $payment->id }})" wire:target="accept({{ $payment->id }})">Accept</x-ui.button>
                            </div>
                            @if ($type->paidAsSssBenefit())
                                <p class="text-right text-xs text-gray-500 dark:text-gray-400">The employer advances the SSS maternity benefit (from the SSS salary credit), and SSS reimburses it. Added as a non-taxable allowance.</p>
                            @endif
                        @elseif ($payment->status === \App\Models\PaidLeavePayment::ACCEPTED)
                            <p class="text-right text-xs text-gray-500 dark:text-gray-400">On this payslip</p>
                        @endif

                        @if ($dismissingId === $payment->id)
                            <form wire:submit="dismiss" class="flex flex-wrap items-start gap-2">
                                <div class="min-w-64 flex-1"><x-ui.input label="Why isn't it paid?" wire:model="dismissReason" placeholder="e.g. Already paid in the Oct 1–15 run" /></div>
                                <div class="flex gap-2 sm:mt-7">
                                    <x-ui.button type="button" size="sm" variant="ghost" wire:click="$set('dismissingId', null)">Cancel</x-ui.button>
                                    <x-ui.button size="sm" variant="danger-outline" wire:target="dismiss">Dismiss</x-ui.button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
