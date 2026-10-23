<div class="mx-auto max-w-3xl space-y-6">
    {{-- A uniform's price waits for their authority to deduct (Art. 113), as on My Payslips. --}}
    <livewire:payroll.deduction-authorizations />

    <x-ui.card title="My Equipment" description="Uniforms, radios and gear issued to you. Company property stays the company's: take care of it and return it when asked or when you leave.">
        <x-slot:actions>
            <x-ui.button type="button" size="sm" wire:click="startRequest"><x-icon name="package" class="h-4 w-4" /> Request an item</x-ui.button>
        </x-slot:actions>

        @if ($this->mine->contains(fn ($issue) => $issue->status === \App\Enums\EquipmentStatus::Issued && $issue->acknowledged_at === null))
            <div class="px-6 pt-4">@include('livewire.partials.acknowledgement-language')</div>
        @endif

        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->mine as $issue)
                <li wire:key="mine-{{ $issue->id }}" class="space-y-3 px-6 py-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $issue->describe() }}
                            <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $issue->charged ? 'Uniform, ₱'.number_format($issue->total(), 2) : ($issue->status === \App\Enums\EquipmentStatus::Requested ? 'Requested' : 'Company property') }}</span>
                        </p>
                        <x-ui.badge :tone="$issue->status->tone()">{{ $issue->status->label() }}</x-ui.badge>
                    </div>

                    @if ($issue->status === \App\Enums\EquipmentStatus::Issued)
                        <p class="text-xs text-gray-500 dark:text-gray-400">Issued {{ $issue->issued_at?->format('M j, Y') }} by {{ $issue->issuer?->name ?? '—' }}</p>
                        @if ($issue->acknowledged_at === null)
                            <p class="rounded-lg border border-gray-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300" lang="{{ $ackLocale }}">“{{ \App\Actions\Equipment\AcknowledgeEquipment::text($issue, $ackLocale) }}”</p>
                            <div class="flex justify-end">
                                <x-ui.button type="button" size="sm" variant="dark" wire:click="acknowledge({{ $issue->id }})" wire:target="acknowledge({{ $issue->id }})">{{ \App\Support\Acknowledgements::line('ui.received', $ackLocale) }}</x-ui.button>
                            </div>
                        @else
                            <p class="text-xs text-gray-500 dark:text-gray-400">You confirmed receiving it on {{ $issue->acknowledged_at->format('M j, Y') }}.</p>
                        @endif
                    @elseif ($issue->status === \App\Enums\EquipmentStatus::Requested)
                        <p class="text-xs text-gray-500 dark:text-gray-400">Asked {{ $issue->requested_at?->format('M j, Y') }}{{ $issue->request_note ? ' · “'.$issue->request_note.'”' : '' }}</p>
                    @elseif ($issue->status === \App\Enums\EquipmentStatus::Rejected)
                        <p class="text-xs text-gray-500 dark:text-gray-400">Not approved: {{ $issue->reject_reason }}</p>
                    @elseif ($issue->status === \App\Enums\EquipmentStatus::Returned)
                        <p class="text-xs text-gray-500 dark:text-gray-400">Returned {{ $issue->returned_at?->format('M j, Y') }}</p>
                    @endif

                    @foreach ($issue->incidents as $incident)
                        <div wire:key="incident-{{ $incident->id }}" class="space-y-2 rounded-lg border border-amber-200 px-3 py-2.5 text-sm dark:border-amber-500/30">
                            <p class="font-medium text-amber-700 dark:text-amber-400">Reported {{ strtolower($incident->kind->label()) }} on {{ $incident->created_at->format('M j, Y') }}</p>
                            <p class="text-gray-700 dark:text-gray-300">{{ $incident->details }}</p>
                            @if ($incident->explanation)
                                <p class="text-gray-600 dark:text-gray-400">Your side: “{{ $incident->explanation }}”</p>
                            @endif
                            @if ($incident->decision)
                                <p class="text-gray-700 dark:text-gray-300">
                                    <span class="font-medium">{{ $incident->decision->label() }}{{ $incident->loss_amount ? ': ₱'.number_format((float) $incident->loss_amount, 2).', ₱'.number_format((float) $incident->installment, 2).' per payslip' : '' }}.</span>
                                    {{ $incident->decision_reason }}
                                </p>
                            @elseif ($incident->canStillExplain())
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-gray-600 dark:text-gray-400">You may explain until {{ $incident->explain_by->format('M j, Y') }}. Nothing is charged before you are heard.</p>
                                    <x-ui.button type="button" size="sm" variant="dark" wire:click="startExplain({{ $incident->id }})">Explain</x-ui.button>
                                </div>
                            @else
                                <p class="text-gray-600 dark:text-gray-400">Waiting for the decision.</p>
                            @endif
                        </div>
                    @endforeach
                </li>
            @empty
                <li class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Nothing issued to you yet.</li>
            @endforelse
        </ul>
    </x-ui.card>

    @if ($requesting)
        @php $picked = $this->pickedItem(); @endphp
        <x-ui.modal title="Request an item" close="closeDialogs" max-width="md">
            <form id="request-form" wire:submit="request" class="space-y-4">
                <x-ui.select label="Item" wire:model.live="requestItem">
                    <option value="">Choose an item</option>
                    @foreach ($this->items as $item)
                        <option value="{{ $item->id }}">{{ $item->name }}{{ $item->kind === \App\Enums\EquipmentKind::Uniform ? ' · '.$item->priceLabel().' (charged)' : '' }}</option>
                    @endforeach
                </x-ui.select>
                <div class="grid grid-cols-2 gap-4">
                    @if ($picked?->has_sizes)
                        <x-ui.select label="Size" wire:model="requestSize">
                            <option value="">Choose</option>
                            @foreach (\App\Models\EquipmentItem::SIZES as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach
                        </x-ui.select>
                    @endif
                    <x-ui.input label="Quantity" type="number" min="1" max="{{ \App\Actions\Equipment\RequestEquipment::MAX_QUANTITY }}" wire:model="requestQuantity" />
                </div>
                <x-ui.textarea label="Note (optional)" wire:model="requestNote" rows="2" maxlength="500" placeholder="e.g. My polo is torn" />
                @if ($picked?->kind === \App\Enums\EquipmentKind::Uniform)
                    <x-ui.alert tone="blue" class="text-xs">A uniform is charged to you. Nothing is deducted until you authorize it, after it's issued.</x-ui.alert>
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button form="request-form" wire:loading.attr="disabled" wire:target="request">Send request</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($explainingId)
        <x-ui.modal title="Your side" close="closeDialogs" max-width="md">
            <form id="explain-form" wire:submit="explain">
                <x-ui.textarea label="What happened, in your own words" wire:model="explanation" rows="5" maxlength="3000" hint="Sent once. Your head reads it before deciding." />
            </form>
            <x-slot:footer>
                <x-ui.button form="explain-form" wire:loading.attr="disabled" wire:target="explain">Send</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
