@php
    $open = $this->corrections->where('status', \App\Models\PriorPeriodCorrection::SUGGESTED);
    $signed = fn (float $amount): string => ($amount >= 0 ? '+' : '−').'₱'.number_format(abs($amount), 2);
@endphp

{{-- Pay that changed after a cut-off was paid. Calm when there's nothing: one line and "Check again". --}}
<div>
    @if ($this->corrections->isEmpty())
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm dark:border-gray-800 dark:bg-gray-900">
            <span class="text-gray-500 dark:text-gray-400">Earlier cut-offs: no pay changed since they were paid.</span>
            <button type="button" wire:click="check" wire:target="check" class="font-medium text-gray-700 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400">Check again</button>
        </div>
    @else
        <x-ui.card title="Corrections from earlier cut-offs"
            description="Pay that changed after a cut-off was paid: a day recorded by hand, overtime or leave approved late, a back-dated rate. Accept to add it to this payslip (taxed as a Correction), or dismiss it with a reason. Payslips wait until it's decided.">
            <x-slot:actions>
                <x-ui.button type="button" variant="ghost" size="sm" wire:click="check" wire:target="check">Check again</x-ui.button>
                @if ($open->count() > 1)
                    <x-ui.button type="button" variant="secondary" size="sm" wire:click="acceptAll" wire:target="acceptAll" wire:confirm="Add all {{ $open->count() }} corrections to this run's payslips?">Accept all</x-ui.button>
                @endif
            </x-slot:actions>

            @error('correction') <x-ui.alert tone="red" class="mx-4 mt-3">{{ $message }}</x-ui.alert> @enderror
            @error('adjustmentAmount') <x-ui.alert tone="red" class="mx-4 mt-3">{{ $message }}</x-ui.alert> @enderror

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            <th class="ui-th">Employee</th>
                            <th class="ui-th">Cut-off</th>
                            <th class="ui-th">What changed</th>
                            <th class="ui-th text-right">Amount</th>
                            <th class="ui-th text-right"><span class="sr-only">Decision</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($this->corrections as $correction)
                            <tr wire:key="correction-{{ $correction->id }}" @class(['opacity-60' => $correction->status === \App\Models\PriorPeriodCorrection::DISMISSED])>
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $correction->user->name }}</td>
                                <td class="ui-td whitespace-nowrap">{{ $correction->fromRun->periodLabel() }}</td>
                                <td class="ui-td text-gray-600 dark:text-gray-300">
                                    {{ ucfirst($correction->summary()) }}
                                    @if ($correction->status === \App\Models\PriorPeriodCorrection::DISMISSED)
                                        <span class="block text-xs text-gray-400">Dismissed: {{ $correction->dismiss_reason }}</span>
                                    @endif
                                </td>
                                <td class="ui-td text-right font-medium whitespace-nowrap text-gray-800 tabular-nums dark:text-gray-100">{{ $signed((float) $correction->amount) }}</td>
                                <td class="ui-td text-right whitespace-nowrap">
                                    @if ($correction->status === \App\Models\PriorPeriodCorrection::SUGGESTED)
                                        <div class="flex justify-end gap-2">
                                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="startDismiss({{ $correction->id }})">Dismiss</x-ui.button>
                                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="accept({{ $correction->id }})" wire:target="accept({{ $correction->id }})">Accept</x-ui.button>
                                        </div>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                            @if ($correction->status === \App\Models\PriorPeriodCorrection::ACCEPTED)
                                                <x-ui.verdict-dot tone="blue" label="" /> On this payslip
                                            @else
                                                Not paid
                                            @endif
                                        </span>
                                    @endif
                                </td>
                            </tr>
                            @if ($dismissingId === $correction->id)
                                <tr wire:key="dismiss-{{ $correction->id }}">
                                    <td colspan="5" class="px-4 pb-4">
                                        <form wire:submit="dismiss" class="flex flex-wrap items-start gap-2">
                                            <div class="min-w-64 flex-1">
                                                <x-ui.input label="Why isn't it paid?" wire:model="dismissReason" placeholder="e.g. Paid in cash on Oct 20" />
                                            </div>
                                            <div class="flex gap-2 sm:mt-7">
                                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="$set('dismissingId', null)">Cancel</x-ui.button>
                                                <x-ui.button size="sm" variant="danger-outline" wire:target="dismiss">Dismiss</x-ui.button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
