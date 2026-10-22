<div class="mx-auto max-w-4xl space-y-6">
    <div class="grid grid-cols-1 gap-6 md:grid-cols-5">
        <x-ui.card class="md:col-span-3" title="Borrow load" description="Nothing to pay now. The amount is deducted from your next payslip once approved.">
            <form id="buy-load" wire:submit="submit" class="space-y-5 p-4">
                <fieldset>
                    <legend class="ui-label">Network</legend>
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        @foreach ($networks as $value => $label)
                            <label class="flex min-h-10 cursor-pointer items-center justify-center rounded-md border px-2 text-sm font-medium has-checked:border-blue-600 has-checked:bg-blue-50 has-checked:text-blue-700 dark:border-gray-700 dark:has-checked:bg-blue-500/10 dark:has-checked:text-blue-300">
                                <input type="radio" wire:model="network" value="{{ $value }}" class="sr-only">{{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('network')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </fieldset>

                <x-ui.input label="Mobile number" wire:model="mobileNumber" inputmode="tel" autocomplete="tel" placeholder="0917 123 4567" />

                <fieldset>
                    <legend class="ui-label">Amount</legend>
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        @foreach ($amounts as $value)
                            <label class="flex min-h-10 cursor-pointer items-center justify-center rounded-md border px-2 text-sm font-semibold has-checked:border-blue-600 has-checked:bg-blue-50 has-checked:text-blue-700 dark:border-gray-700 dark:has-checked:bg-blue-500/10 dark:has-checked:text-blue-300">
                                <input type="radio" wire:model.live="amount" value="{{ $value }}" class="sr-only">₱{{ number_format($value) }}
                            </label>
                        @endforeach
                    </div>
                    @error('amount')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </fieldset>

                {{-- The written authorization (Labor Code Art. 113) to take it from their pay: required, saved word for word. --}}
                {{-- It names the amount, so it shows once one is chosen. --}}
                @if ($amount > 0)
                    <div class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        @include('livewire.partials.acknowledgement-language')
                        <x-ui.checkbox id="load-authorized" wire:model="authorized" :label="\App\Models\LoadRequest::authorizationText($amount, $ackLocale)"
                            description="Only once it's approved and sent. Nothing to pay now." />
                        @error('authorized')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">Choose an amount. It's deducted from your next payslip once approved.</p>
                @endif
            </form>
            <x-slot:footer>
                <div class="flex justify-end">
                    <x-ui.button form="buy-load" wire:loading.attr="disabled" wire:target="submit">Send request</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>

        <x-ui.card class="md:col-span-2" title="How it works">
            <div class="space-y-4 p-4">
                <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                    <li>Send your request: network, number and amount.</li>
                    <li>An Admin sends the load to your number and approves it. You get the receipt (the load confirmation) by email and here.</li>
                    <li>The amount comes off your <b>next payslip</b>, listed as "Borrowed load".</li>
                </ol>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                    <p class="text-xs text-gray-500">To be deducted from your next payslip</p>
                    <p class="mt-0.5 text-xl font-semibold text-gray-800 tabular-nums dark:text-gray-100">₱{{ number_format($this->owed, 2) }}</p>
                </div>
            </div>
        </x-ui.card>
    </div>

    <x-ui.card title="My load requests">
        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Filed</th><th class="ui-th">Load</th><th class="ui-th">Number</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->requests as $request)
                        <tr wire:key="load-{{ $request->id }}">
                            <td class="ui-td"><x-filed-at :at="$request->created_at" /></td>
                            <td class="ui-td font-medium whitespace-nowrap text-gray-800 dark:text-gray-100">₱{{ number_format($request->amount) }} {{ $request->networkLabel() }}</td>
                            <td class="ui-td font-mono text-xs whitespace-nowrap">{{ $request->formattedNumber() }}</td>
                            <td class="ui-td">
                                <x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge>
                                @if ($request->deduction)
                                    <p class="mt-1 text-xs text-gray-500">{{ (float) $request->deduction->remaining_balance > 0 ? 'To be deducted from your next payslip' : 'Deducted from your payslip' }}</p>
                                @endif
                                @if ($request->rejection_reason)
                                    <p class="mt-1 max-w-xs text-xs text-red-600 dark:text-red-400">{{ $request->rejection_reason }}</p>
                                @endif
                            </td>
                            <td class="ui-td text-right whitespace-nowrap">
                                @if ($request->hasProof())
                                    <a href="{{ route('load.receipt', $request) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300"><x-icon name="eye" class="h-4 w-4" /> Receipt</a>
                                @endif
                                @if ($request->status->canMoveTo(\App\Enums\LoadRequestStatus::Cancelled))
                                    <button type="button" wire:click="cancel({{ $request->id }})" wire:confirm="Cancel this load request?" class="font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white">Cancel</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-td py-8 text-center">No load requests yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
