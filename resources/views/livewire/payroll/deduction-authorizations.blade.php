{{-- Hidden when empty, so it adds no gap to the page's spacing. --}}
<div @class(['hidden' => $this->waiting->isEmpty()])>
    {{-- Deductions Accounting asked them to authorize (Labor Code Art. 113): nothing is taken until they do. --}}
    @if ($this->waiting->isNotEmpty())
        <x-ui.card title="Please review" description="These were added to your payslips (a loan, or a uniform you received). Nothing is deducted until you authorize it.">
            <div class="px-6 pt-4">@include('livewire.partials.acknowledgement-language')</div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($this->waiting as $deduction)
                    <li wire:key="authorize-{{ $deduction->id }}" class="space-y-3 px-6 py-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $deduction->label }} <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $deduction->type->label() }}</span></p>
                            <p class="text-sm text-gray-700 tabular-nums dark:text-gray-300">
                                ₱{{ number_format((float) $deduction->installment_amount, 2) }} per payslip
                                @if ($deduction->original_amount !== null)<span class="text-gray-500 dark:text-gray-400">· ₱{{ number_format((float) $deduction->original_amount, 2) }} in all</span>@endif
                            </p>
                        </div>
                        <p class="rounded-lg border border-gray-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300" lang="{{ $ackLocale }}">“{{ $deduction->authorizationText($ackLocale) }}”</p>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <x-ui.button type="button" variant="secondary" size="sm" wire:click="decline({{ $deduction->id }})" wire:target="decline({{ $deduction->id }})"
                                wire:confirm="Tell Accounting you don't agree? Nothing will be deducted.">I don't agree</x-ui.button>
                            <x-ui.button type="button" size="sm" wire:click="authorizeDeduction({{ $deduction->id }})" wire:target="authorizeDeduction({{ $deduction->id }})">{{ \App\Support\Acknowledgements::line('ui.authorize', $ackLocale) }}</x-ui.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
