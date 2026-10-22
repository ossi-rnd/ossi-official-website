@php
    $peso = fn (float|string $amount): string => '₱'.number_format((float) $amount, 2);
@endphp

<div>
    <x-ui.card title="Final Pay" description="For people who resigned. Accounting prepares and signs the statement from the last day, then the President or Vice President signs, which releases it. Due within 30 days of the last day.">
        <x-slot:actions>
            <x-ui.select wire:model.live="statusFilter" aria-label="Filter">
                <option value="open">Not released yet</option>
                <option value="released">Released</option>
                <option value="all">All</option>
            </x-ui.select>
        </x-slot:actions>

        <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-50" wire:target="statusFilter">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Employee</th><th class="ui-th">Last day</th><th class="ui-th">Due</th><th class="ui-th">Status</th><th class="ui-th text-right">Net</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->resignations as $resignation)
                        @php
                            $pay = $resignation->finalPay;
                            $lastDayPassed = ! today()->lt($resignation->effectiveLastDay());
                            $overdue = ($pay?->status !== \App\Enums\FinalPayStatus::Released) && today()->gt($resignation->finalPayDue());
                        @endphp
                        <tr wire:key="final-pay-{{ $resignation->id }}">
                            <td class="ui-td">
                                <p><x-person-link :user="$resignation->user" /></p>
                                <p class="text-xs text-gray-500">{{ $resignation->user->employeeProfile?->assignmentLabel() ?? '—' }}</p>
                            </td>
                            <td class="ui-td whitespace-nowrap">{{ $resignation->effectiveLastDay()->format('M j, Y') }}</td>
                            <td class="ui-td whitespace-nowrap">{{ $resignation->finalPayDue()->format('M j, Y') }}@if ($overdue)<span class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"><span class="size-1.5 rounded-full bg-amber-500"></span>overdue</span>@endif</td>
                            <td class="ui-td">
                                @if ($pay === null)
                                    <x-ui.badge>{{ $lastDayPassed ? 'To prepare' : 'After the last day' }}</x-ui.badge>
                                @else
                                    <x-ui.badge :tone="$pay->status->tone()">{{ $pay->status->label() }}</x-ui.badge>
                                    @if ($pay->status === \App\Enums\FinalPayStatus::Signing)
                                        <span class="mt-1 block text-xs text-gray-500">{{ count($signers) - count($pay->pendingSigners()) }} of {{ count($signers) }} signed</span>
                                    @endif
                                @endif
                            </td>
                            <td class="ui-td text-right tabular-nums">{{ $pay ? $peso($pay->net) : '—' }}</td>
                            <td class="ui-td text-right"><button type="button" wire:click="open({{ $resignation->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Open</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-td py-8 text-center">No final pay here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>{{ $this->resignations->links() }}</x-slot:footer>
    </x-ui.card>

    @if ($opened = $this->opened)
        @php
            $pay = $opened->finalPay;
            $draft = $pay?->status === \App\Enums\FinalPayStatus::Draft;
            $signAs = $this->signAs();
        @endphp
        <x-ui.modal :title="$opened->user->name.' · final pay'" close="close" max-width="3xl">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Last day {{ $opened->effectiveLastDay()->format('M j, Y') }} · due {{ $opened->finalPayDue()->format('M j, Y') }}
                · {{ $opened->deduction_consent_at ? 'consented to deductions '.$opened->deduction_consent_at->format('M j') : 'no consent to deductions' }}
                @if ($pay?->released_at) · <span class="font-medium text-gray-800 dark:text-gray-100">released {{ $pay->released_at->format('M j, Y') }}</span> @endif
            </p>

            @error('finalPay') <x-ui.alert tone="red" class="mt-3">{{ $message }}</x-ui.alert> @enderror

            @if ($pay === null)
                @php $notYet = $opened->effectiveLastDay()->isAfter(today()); @endphp
                <div class="mt-4 rounded-lg border border-dashed border-gray-300 px-6 py-8 text-center dark:border-gray-700">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100">Not prepared yet</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">
                        @if ($notYet)
                            {{ $this->canPrepare() ? 'You can prepare it' : 'Accounting prepares it' }} from the last day, {{ $opened->effectiveLastDay()->format('M j, Y') }}: the 13th month, the cash bond refund and what's owed are known only then.
                        @elseif ($this->canPrepare())
                            It computes the 13th month, the cash bond refund and what's owed, up to the last day.
                        @else
                            Waiting for Accounting to prepare it. You'll be asked to sign once it's sent.
                        @endif
                    </p>
                </div>
            @else
                @if ($pay->return_note && $draft)
                    <x-ui.alert tone="amber" class="mt-3">Sent back: {{ $pay->return_note }}</x-ui.alert>
                @endif
                @foreach ($this->warnings as $warning)
                    <x-ui.alert tone="amber" class="mt-3">{{ $warning }}</x-ui.alert>
                @endforeach

                {{-- The statement: what's added, what's taken off, and what's only shown. --}}
                <div class="mt-4 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($pay->lines ?? [] as $line)
                                <tr @class(['bg-gray-50/60 dark:bg-white/[0.02]' => $line['kind'] === 'info'])>
                                    <td class="px-4 py-2.5">
                                        <span @class(['font-medium text-gray-800 dark:text-gray-100' => $line['kind'] !== 'info', 'text-gray-500 dark:text-gray-400' => $line['kind'] === 'info'])>{{ $line['label'] }}</span>
                                        @if ($line['note'])<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $line['note'] }}</span>@endif
                                    </td>
                                    <td @class(['px-4 py-2.5 text-right whitespace-nowrap tabular-nums', 'text-gray-800 dark:text-gray-100' => $line['kind'] === 'pay', 'text-gray-800 dark:text-gray-100' => $line['kind'] === 'deduct', 'text-gray-400 dark:text-gray-500' => $line['kind'] === 'info'])>
                                        {{ $line['kind'] === 'deduct' ? '−' : ($line['kind'] === 'info' ? '' : '+') }}{{ $peso($line['amount']) }}
                                        @if ($line['kind'] === 'info')<span class="block text-[11px]">not counted</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-gray-200 bg-gray-50 text-sm dark:border-gray-800 dark:bg-white/[0.03]">
                            <tr><td class="px-4 pt-3 text-gray-500 dark:text-gray-400">Added</td><td class="px-4 pt-3 text-right tabular-nums">{{ $peso($pay->total_pay) }}</td></tr>
                            <tr><td class="px-4 text-gray-500 dark:text-gray-400">Taken off</td><td class="px-4 text-right tabular-nums">−{{ $peso($pay->total_deducted) }}</td></tr>
                            <tr><td class="px-4 pt-1 pb-3 font-semibold text-gray-800 dark:text-gray-100">Final pay</td><td class="px-4 pt-1 pb-3 text-right text-base font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $peso($pay->net) }}</td></tr>
                            @if ((float) $pay->receivable > 0)
                                <tr><td colspan="2" class="border-t border-gray-200 px-4 py-2 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400"><span class="mr-1.5 inline-block size-1.5 rounded-full bg-amber-500 align-middle"></span>{{ $peso($pay->receivable) }} still owed after this: not taken below zero, collect it separately.</td></tr>
                            @endif
                        </tfoot>
                    </table>
                </div>

                {{-- Accounting's adjustments, on a draft. --}}
                @if ($draft && $this->canPrepare())
                    <div class="mt-4">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Adjustments <span class="font-normal">· + adds, − takes off, each with a reason</span></p>
                        @foreach ($pay->adjustments ?? [] as $i => $adjustment)
                            <div wire:key="adjustment-{{ $i }}" class="mt-1 flex items-center justify-between gap-3 text-sm">
                                <span class="text-gray-700 dark:text-gray-300">{{ $adjustment['label'] }} · {{ $peso($adjustment['amount']) }} <span class="text-xs text-gray-500">({{ $adjustment['reason'] }})</span></span>
                                <x-ui.row-delete wire:click="removeAdjustment({{ $i }})" wire:confirm="Remove this adjustment?">Remove</x-ui.row-delete>
                            </div>
                        @endforeach
                        {{-- Top-aligned, so an error under one field doesn't lift the others; the button gets an empty label row to line up with the boxes. --}}
                        <form wire:submit="addAdjustment" class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_8rem_1.4fr_auto] sm:items-start">
                            <x-ui.input label="Label" wire:model="adjustmentLabel" placeholder="e.g. Damaged radio" />
                            <x-ui.input label="Amount" type="number" step="0.01" wire:model="adjustmentAmount" placeholder="-500" />
                            <x-ui.input label="Reason" wire:model="adjustmentReason" placeholder="e.g. Not returned at turnover" />
                            <div>
                                <span class="ui-label invisible hidden sm:block" aria-hidden="true">Add</span>
                                <div class="sm:mt-1"><x-ui.button variant="secondary" wire:target="addAdjustment">Add</x-ui.button></div>
                            </div>
                        </form>
                    </div>
                @endif

                {{-- The signatures, the President or Vice President last. --}}
                @if ($pay->status !== \App\Enums\FinalPayStatus::Draft)
                    <div class="mt-5">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Signatures</p>
                        <ol class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($signers as $role)
                                @php $signed = ($pay->signoffs ?? [])[$role->value] ?? null; @endphp
                                <li @class(['rounded-md border p-2.5 text-xs', 'border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03]' => $signed, 'border-gray-200 dark:border-gray-800' => ! $signed])>
                                    <span class="flex items-center gap-1.5 font-medium text-gray-800 dark:text-gray-100">
                                        @if ($signed)<x-ui.verdict-dot tone="blue" label="" />@endif {{ \App\Models\FinalPay::signerLabel($role) }}
                                    </span>
                                    @if ($signed)
                                        <span class="mt-0.5 block truncate text-gray-500 dark:text-gray-400" title="{{ $signed['by'] }}">{{ $signed['by'] }}</span>
                                        <span class="block text-gray-400 dark:text-gray-500">{{ \Carbon\CarbonImmutable::parse($signed['at'])->format('M j, g:i A') }}</span>
                                    @else
                                        <span class="mt-0.5 block text-gray-500 dark:text-gray-400">{{ $role === \App\Enums\Role::President ? 'signs last' : 'to sign' }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif

                @if ($this->canSendBack())
                    <form id="return-form" wire:submit="sendBack" class="mt-4">
                        {{-- The input shows its own error. A note is required to send back, so not "(optional)". --}}
                        <x-ui.input label="To send it back, say what needs changing" wire:model="returnNote" placeholder="e.g. The uniform refund is missing" />

                    </form>
                @endif
            @endif

            @php $canPrepareNow = $this->canPrepare() && ($pay !== null || ! $opened->effectiveLastDay()->isAfter(today())); @endphp
            @if ($this->canSendBack() || ($canPrepareNow && ($pay === null || $draft)) || $signAs)
            <x-slot:footer>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    @if ($this->canSendBack())
                        <x-ui.button variant="danger-outline" form="return-form" wire:target="sendBack">Send back</x-ui.button>
                    @endif
                    @if ($canPrepareNow && ($pay === null || $draft))
                        <x-ui.button :variant="$pay ? 'secondary' : 'primary'" type="button" wire:click="prepare" wire:target="prepare">{{ $pay ? 'Recompute' : 'Prepare final pay' }}</x-ui.button>
                    @endif
                    @if ($this->canPrepare() && $draft)
                        <x-ui.button type="button" wire:click="submit" wire:target="submit" wire:confirm="Send this statement for signatures? It can't change unless someone sends it back.">Send for signatures</x-ui.button>
                    @endif
                    @if ($signAs)
                        <x-ui.button type="button" wire:click="sign" wire:target="sign" wire:confirm="{{ $signAs === \App\Enums\Role::President ? 'Sign as the President or Vice President and release this final pay?' : 'Sign as '.\App\Models\FinalPay::signerLabel($signAs).'?' }}">
                            {{ $signAs === \App\Enums\Role::President ? 'Sign and release' : 'Sign as '.\App\Models\FinalPay::signerLabel($signAs) }}
                        </x-ui.button>
                    @endif
                </div>
            </x-slot:footer>
            @endif
        </x-ui.modal>
    @endif
</div>
