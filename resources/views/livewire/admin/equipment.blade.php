<div class="space-y-6">
    @php
        $tab = $this->shownTab();
        $viewer = auth()->user();
        $tabs = $this->issues()
            ? ['requests' => 'Requests', 'out' => 'With people', 'incidents' => 'Damage & loss', 'history' => 'History']
            : ['incidents' => 'Damage & loss'];
        $money = fn ($amount) => '₱'.number_format((float) $amount, 2);
    @endphp

    <x-ui.card title="Equipment" :description="$this->issues()
        ? 'Uniforms, radios and gear. A uniform is charged, once they authorize the deduction. Company property is theirs to look after, and is charged only if lost or damaged through their fault, after they are heard.'
        : 'Damage and loss reports for the people you head. You decide them once they have explained, or the days to explain have passed.'">
        <x-slot:actions>
            <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or item" aria-label="Search equipment" class="w-full sm:w-56" />
            @if ($this->issues())
                <x-ui.button type="button" size="sm" wire:click="startIssue"><x-icon name="package" class="h-4 w-4" /> Issue equipment</x-ui.button>
            @endif
        </x-slot:actions>

        <div class="flex gap-1 overflow-x-auto border-b border-gray-200 px-3 dark:border-gray-800" role="tablist" aria-label="Show">
            @foreach ($tabs as $value => $label)
                <button type="button" role="tab" wire:click="$set('show', '{{ $value }}')" aria-selected="{{ $tab === $value ? 'true' : 'false' }}"
                    @class(['-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap', 'border-blue-600 text-blue-600 dark:text-blue-400' => $tab === $value, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $tab !== $value])>
                    {{ $label }}
                    @if (in_array($value, ['requests', 'incidents'], true) && ($this->counts[$value] ?? 0) > 0)
                        <span class="rounded-full bg-amber-100 px-1.5 text-xs text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ $this->counts[$value] }}</span>
                    @elseif ($value === 'out')
                        <span class="text-xs text-gray-400 tabular-nums">{{ $this->counts['out'] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            @if ($tab === 'requests')
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Who</th><th class="ui-th">Asked for</th><th class="ui-th">When</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->requests as $request)
                            <tr wire:key="request-{{ $request->id }}">
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $request->user->name }}</td>
                                <td class="ui-td">
                                    <p>{{ $request->describe() }}</p>
                                    @if ($request->request_note)<p class="text-xs text-gray-500 dark:text-gray-400">“{{ $request->request_note }}”</p>@endif
                                </td>
                                <td class="ui-td whitespace-nowrap">{{ $request->requested_at?->format('M j, g:i A') }}</td>
                                <td class="ui-td text-right whitespace-nowrap">
                                    <button type="button" wire:click="startReject({{ $request->id }})" class="mr-3 font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">Not approve</button>
                                    <button type="button" wire:click="startIssue({{ $request->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Issue</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ui-td py-8 text-center">No requests waiting.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @elseif ($tab === 'out')
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Who</th><th class="ui-th">Item</th><th class="ui-th">Issued</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->out as $issue)
                            @php $open = $issue->openIncident(); @endphp
                            <tr wire:key="out-{{ $issue->id }}">
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $issue->user->name }}</td>
                                <td class="ui-td">
                                    <p>{{ $issue->describe() }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $issue->charged ? 'Uniform, charged '.$money($issue->total()) : 'Company property' }}</p>
                                </td>
                                <td class="ui-td whitespace-nowrap">{{ $issue->issued_at?->format('M j, Y') }}<p class="text-xs text-gray-500 dark:text-gray-400">by {{ $issue->issuer?->name ?? '—' }}</p></td>
                                <td class="ui-td">
                                    <div class="flex flex-wrap gap-1">
                                        @if ($issue->acknowledged_at)
                                            <x-ui.badge>Confirmed {{ $issue->acknowledged_at->format('M j') }}</x-ui.badge>
                                        @else
                                            <x-ui.badge tone="amber">Not confirmed yet</x-ui.badge>
                                        @endif
                                        @if ($issue->charged && $issue->deduction?->declined_at)
                                            <x-ui.badge tone="red">Deduction declined</x-ui.badge>
                                        @elseif ($issue->charged && $issue->deduction?->awaiting_authorization)
                                            <x-ui.badge tone="amber">Deduction not authorized yet</x-ui.badge>
                                        @endif
                                        @if ($open)<x-ui.badge tone="amber">Reported {{ $open->kind->value }}</x-ui.badge>@endif
                                    </div>
                                </td>
                                <td class="ui-td text-right whitespace-nowrap">
                                    @if ($issue->isCompanyProperty())
                                        @unless ($open)
                                            <button type="button" wire:click="startReport({{ $issue->id }})" class="mr-3 font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">Report damage or loss</button>
                                        @endunless
                                        <button type="button" wire:click="markReturned({{ $issue->id }})" wire:confirm="Record {{ $issue->describe() }} as returned by {{ $issue->user->name }}?" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Returned</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="ui-td py-8 text-center">{{ $search !== '' ? 'Nothing matches.' : 'Nothing issued yet.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @elseif ($tab === 'incidents')
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Who</th><th class="ui-th">Report</th><th class="ui-th">Their side</th><th class="ui-th">Decision</th></tr></thead>
                    <tbody>
                        @forelse ($this->incidents as $incident)
                            @php $person = $incident->issue->user; @endphp
                            <tr wire:key="incident-{{ $incident->id }}">
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $person->name }}</td>
                                <td class="ui-td">
                                    <p><span class="font-medium">{{ $incident->kind->label() }}:</span> {{ $incident->issue->describe() }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $incident->details }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Reported {{ $incident->created_at->format('M j') }} by {{ $incident->reporter->name }}</p>
                                </td>
                                <td class="ui-td">
                                    @if ($incident->explanation)
                                        <p class="text-sm">“{{ $incident->explanation }}”</p>
                                    @elseif ($incident->canStillExplain())
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Can explain until {{ $incident->explain_by->format('M j') }}</p>
                                    @elseif ($incident->decision === null)
                                        <p class="text-xs text-gray-500 dark:text-gray-400">No explanation; the days passed</p>
                                    @else
                                        <p class="text-xs text-gray-500 dark:text-gray-400">No explanation</p>
                                    @endif
                                </td>
                                <td class="ui-td">
                                    @if ($incident->decision)
                                        <x-ui.badge :tone="$incident->decision === \App\Enums\EquipmentIncidentDecision::Charged ? 'amber' : 'gray'">{{ $incident->decision->label() }}{{ $incident->loss_amount ? ' '.$money($incident->loss_amount) : '' }}</x-ui.badge>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $incident->decider?->name }}: {{ $incident->decision_reason }}</p>
                                    @elseif (\App\Models\EquipmentIncident::canDecide($viewer, $person))
                                        @if ($incident->readyToDecide())
                                            <x-ui.button type="button" size="sm" variant="dark" wire:click="startDecide({{ $incident->id }})">Decide</x-ui.button>
                                        @else
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Decide after {{ $incident->explain_by->format('M j') }}, or once they explain</p>
                                        @endif
                                    @else
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Their head decides: {{ \App\Support\Heads::label($person) }}</p>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ui-td py-8 text-center">No damage or loss reported.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Who</th><th class="ui-th">Item</th><th class="ui-th">Outcome</th></tr></thead>
                    <tbody>
                        @forelse ($this->history as $issue)
                            <tr wire:key="history-{{ $issue->id }}">
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $issue->user->name }}</td>
                                <td class="ui-td">{{ $issue->describe() }}</td>
                                <td class="ui-td">
                                    <x-ui.badge :tone="$issue->status->tone()">{{ $issue->status->label() }}</x-ui.badge>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        @if ($issue->status === \App\Enums\EquipmentStatus::Returned) {{ $issue->returned_at?->format('M j, Y') }}
                                        @elseif ($issue->status === \App\Enums\EquipmentStatus::Rejected) {{ $issue->reject_reason }}
                                        @else {{ $issue->updated_at->format('M j, Y') }} @endif
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="ui-td py-8 text-center">Nothing returned, lost or turned down yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>
    </x-ui.card>

    @can('manage-equipment-items')
        <x-ui.card title="Items and prices" description="What can be issued. A uniform needs its price: it's charged to the employee, with their authority to deduct. Radios come from the Radios masterlist.">
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="startItem"><x-icon name="plus" class="h-4 w-4" /> Add item</x-ui.button>
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Item</th><th class="ui-th">Kind</th><th class="ui-th">Price</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->allItems as $item)
                            <tr wire:key="item-{{ $item->id }}" @class(['opacity-60' => ! $item->is_active])>
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $item->name }}{{ $item->has_sizes ? ' · sizes' : '' }}{{ $item->is_active ? '' : ' · not given out' }}</td>
                                <td class="ui-td">{{ $item->kind->shortLabel() }}</td>
                                <td class="ui-td tabular-nums">{{ $item->priceLabel() }}</td>
                                <td class="ui-td text-right"><button type="button" wire:click="startItem({{ $item->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ui-td py-8 text-center">No items yet. Add your uniforms (with prices) and gear such as flashlights and raincoats.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endcan

    {{-- Issue --}}
    @if ($issuing)
        @php $picked = $this->pickedItem(); @endphp
        <x-ui.modal :title="$fromRequestId ? 'Issue what they asked for' : 'Issue equipment'" close="cancelIssue" max-width="lg">
            <form id="issue-form" wire:submit="issue" class="space-y-4">
                <x-ui.select label="To" wire:model="issueToId" searchable :disabled="$fromRequestId !== null">
                    <option value="">Choose a person</option>
                    @foreach ($this->people as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>

                @unless ($fromRequestId)
                    <div class="flex gap-4 text-sm" role="radiogroup" aria-label="What">
                        <label class="flex items-center gap-2"><input type="radio" wire:model.live="issueKind" value="item" class="text-blue-600 dark:text-blue-400"> From the item list</label>
                        <label class="flex items-center gap-2"><input type="radio" wire:model.live="issueKind" value="radio" class="text-blue-600 dark:text-blue-400"> A radio</label>
                    </div>
                @endunless

                @if ($issueKind === 'radio')
                    <x-ui.select label="Radio (in stock)" wire:model="issueRadioId" searchable>
                        <option value="">Choose a radio</option>
                        @foreach ($this->spareRadios as $radio)
                            <option value="{{ $radio->id }}">{{ $radio->brand }} {{ $radio->model }} · SN {{ $radio->serial_number }}</option>
                        @endforeach
                    </x-ui.select>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Company property: they confirm they received it and are accountable for it. The masterlist shows it with them.</p>
                @else
                    <x-ui.select label="Item" wire:model.live="issueItemId">
                        <option value="">Choose an item</option>
                        @foreach ($this->activeItems as $item)
                            <option value="{{ $item->id }}">{{ $item->name }} · {{ $item->kind->shortLabel() }}{{ $item->unit_price ? ' · '.$item->priceLabel() : '' }}</option>
                        @endforeach
                    </x-ui.select>
                    <div class="grid grid-cols-2 gap-4">
                        @if ($picked?->has_sizes)
                            <x-ui.select label="Size" wire:model="issueSize">
                                <option value="">Choose</option>
                                @foreach (\App\Models\EquipmentItem::SIZES as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach
                            </x-ui.select>
                        @endif
                        <x-ui.input label="Quantity" type="number" min="1" max="{{ \App\Actions\Equipment\IssueEquipment::MAX_QUANTITY }}" wire:model.live.debounce.300ms="issueQuantity" />
                    </div>
                    @if ($picked?->kind === \App\Enums\EquipmentKind::Uniform)
                        <x-ui.input label="Deduct per payslip (₱)" type="number" step="0.01" min="0.01" wire:model="issueInstallment"
                            :hint="'Total '.$money((float) $picked->unit_price * max(1, (int) $issueQuantity)).'. They must authorize it on My Payslips before anything is deducted.'" />
                    @elseif ($picked)
                        <p class="text-xs text-gray-500 dark:text-gray-400">Company property: they confirm they received it and are accountable for it. Nothing is deducted.</p>
                    @endif
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button form="issue-form" wire:loading.attr="disabled" wire:target="issue">Issue</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Not approve --}}
    @if ($rejectingId)
        <x-ui.modal title="Not approve this request" close="closeDialogs" max-width="md">
            <form id="reject-form" wire:submit="reject">
                <x-ui.textarea label="Reason (sent to them)" wire:model="rejectReason" rows="3" maxlength="500" />
            </form>
            <x-slot:footer>
                <x-ui.button form="reject-form" variant="danger">Not approve</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Report damage or loss --}}
    @if ($reportingId)
        <x-ui.modal title="Report damage or loss" close="closeDialogs" max-width="md">
            <form id="report-form" wire:submit="report" class="space-y-4">
                <div class="flex gap-4 text-sm" role="radiogroup" aria-label="What happened">
                    @foreach (\App\Enums\EquipmentIncidentKind::cases() as $kind)
                        <label class="flex items-center gap-2"><input type="radio" wire:model="incidentKind" value="{{ $kind->value }}" class="text-blue-600 dark:text-blue-400"> {{ $kind->label() }}</label>
                    @endforeach
                </div>
                <x-ui.textarea label="What happened" wire:model="incidentDetails" rows="4" maxlength="2000" hint="When and where, and what is damaged or missing. They are sent this and may explain within {{ \App\Models\EquipmentIncident::EXPLAIN_DAYS }} days." />
                <x-ui.alert tone="blue" class="text-xs">Nothing is charged now. Their head decides after hearing them.</x-ui.alert>
            </form>
            <x-slot:footer>
                <x-ui.button form="report-form">Report</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Decide --}}
    @if ($decidingId)
        @php $cap = $this->maxInstallment(); @endphp
        <x-ui.modal title="Decide: was it their fault?" close="closeDialogs" max-width="lg">
            <form id="decide-form" wire:submit="decide" class="space-y-4">
                <fieldset>
                    <legend class="ui-label">Decision</legend>
                    <div class="mt-2 space-y-2 text-sm">
                        <label class="flex items-center gap-2"><input type="radio" wire:model.live="decisionCharge" value="no" class="text-blue-600 dark:text-blue-400"> Not their fault: not charged</label>
                        <label class="flex items-center gap-2"><input type="radio" wire:model.live="decisionCharge" value="charge" class="text-blue-600 dark:text-blue-400"> Their fault: charge the actual loss</label>
                    </div>
                </fieldset>
                <x-ui.textarea :label="$decisionCharge === 'charge' ? 'Why it was their fault (sent to them)' : 'Why they are not charged (sent to them)'" wire:model="decisionReason" rows="3" maxlength="2000" />
                @if ($decisionCharge === 'charge')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.input label="Actual loss (₱)" type="number" step="0.01" min="0.01" wire:model="lossAmount" />
                        <x-ui.input label="Deduct per payslip (₱)" type="number" step="0.01" min="0.01" wire:model="installment"
                            :hint="$cap !== null ? 'At most '.$money($cap).': 20% of a week\'s wages (DOLE).' : 'Their daily rate isn\'t set yet: set it first.'" />
                    </div>
                    <x-ui.input label="How you got the amount" wire:model="amountBasis" maxlength="300" placeholder="e.g. Repair quote from the supplier, Oct 23" hint="Never more than the actual loss." />
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button form="decide-form" wire:loading.attr="disabled" wire:target="decide">Decide</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- The item list --}}
    @if ($itemFormOpen)
        <x-ui.modal :title="$editingItemId ? 'Edit item' : 'Add item'" close="cancelItem" max-width="md">
            <form id="item-form" wire:submit="saveItem" class="space-y-4">
                <x-ui.input label="Name" wire:model="itemName" maxlength="80" placeholder="e.g. Uniform polo, Flashlight, Raincoat" />
                <x-ui.select label="Kind" wire:model.live="itemKind">
                    @foreach (\App\Enums\EquipmentKind::cases() as $kind)
                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input :label="$itemKind === 'uniform' ? 'Price (₱)' : 'Value (₱, optional)'" type="number" step="0.01" min="0" wire:model="itemPrice"
                    :hint="$itemKind === 'uniform' ? 'Charged to the employee for each one.' : 'Shown as a guide if it\'s ever lost; the charge is always the actual loss.'" />
                <x-ui.checkbox id="item-has-sizes" label="Comes in sizes (XS to 3XL)" wire:model="itemHasSizes" />
                <x-ui.checkbox id="item-active" label="Given out" description="Switch off an item you no longer issue. Past records keep it." wire:model="itemActive" />
            </form>
            <x-slot:footer>
                <x-ui.button form="item-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
