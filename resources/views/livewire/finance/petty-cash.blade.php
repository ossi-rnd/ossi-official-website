<div class="space-y-6">
    @php
        $peso = fn (float $amount): string => '₱'.number_format($amount, 2);
        $fund = $this->fund;
        $keeps = $this->keepsFund();
    @endphp

    {{-- Accounting: replenishments to release, across every fund. --}}
    @if ($this->waiting->isNotEmpty())
        <x-ui.card title="Waiting for release" description="The custodian spent this, with the receipts. Check them, then release the cash (the fund is whole again) or return the request with what to fix.">
            @error('decisionNote') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($this->waiting as $request)
                    <li wire:key="waiting-{{ $request->id }}" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <span class="min-w-0">
                            <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $request->fund->name }} · {{ $peso((float) $request->amount) }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $request->requester->name }} · {{ $request->expenses_count }} {{ str('expense')->plural($request->expenses_count) }} · asked {{ $request->created_at->format('M j, g:i A') }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="openFund({{ $request->petty_cash_fund_id }})"><x-icon name="eye" class="h-4 w-4" /> Receipts</x-ui.button>
                            @if ($request->requested_by !== auth()->id())
                                <x-ui.button type="button" variant="secondary" size="sm" wire:click="startReturn({{ $request->id }})"><x-icon name="arrow-path" class="h-4 w-4" /> Return</x-ui.button>
                                <x-ui.button type="button" variant="dark" size="sm" wire:click="release({{ $request->id }})" wire:confirm="Release {{ $peso((float) $request->amount) }} to {{ $request->requester->name }} for {{ $request->fund->name }}?"><x-icon name="check" class="h-4 w-4" /> Release</x-ui.button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    {{-- Every fund (Accounting, leadership): pick one to see its expenses. --}}
    @if ($this->canManage() || $this->funds->count() > 1)
        <x-ui.card title="Funds" description="Each fund is a fixed amount kept by one custodian. Cash on hand is the fund less what's spent and not yet replenished.">
            @if ($this->canManage())
                <x-slot:actions>
                    <x-ui.button type="button" variant="dark" size="sm" wire:click="createFund"><x-icon name="plus" class="h-4 w-4" /> Add fund</x-ui.button>
                </x-slot:actions>
            @endif
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Fund</th><th class="ui-th">Custodian</th><th class="ui-th text-right">Fund</th><th class="ui-th text-right">Cash on hand</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->funds as $row)
                            @php $onHand = $row->cashOnHand(); @endphp
                            <tr wire:key="fund-{{ $row->id }}" @class(['bg-blue-50/50 dark:bg-blue-500/5' => $fund?->id === $row->id, 'opacity-60' => ! $row->is_active])>
                                <td class="ui-td">
                                    <button type="button" wire:click="openFund({{ $row->id }})" class="text-left font-medium text-gray-800 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">{{ $row->name }}</button>
                                    <p class="text-xs text-gray-500">{{ $row->office?->name ?? 'No office' }}{{ $row->is_active ? '' : ' · closed' }}</p>
                                </td>
                                <td class="ui-td">{{ $row->custodian->name }}</td>
                                <td class="ui-td text-right tabular-nums">{{ $peso((float) $row->amount) }}</td>
                                <td class="ui-td text-right tabular-nums">
                                    {{ $peso($onHand) }}
                                    @if ($row->is_active && $onHand < (float) $row->amount * 0.25)
                                        <p class="text-xs text-amber-700 dark:text-amber-400">Running low</p>
                                    @endif
                                </td>
                                <td class="ui-td text-right whitespace-nowrap">
                                    @if ($this->canManage())
                                        <button type="button" wire:click="editFund({{ $row->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="ui-td py-8 text-center">No funds yet. Add one: its name (e.g. Head Office petty cash), the custodian who keeps it, and the amount.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    {{-- One fund: its numbers, its expenses not yet replenished, and its requests. --}}
    @if ($fund)
        @php
            $spent = $fund->spent();
            $notAsked = (float) $this->expenses->whereNull('petty_cash_replenishment_id')->sum('amount');
            $pending = $fund->pendingReplenishment();
        @endphp
        <section aria-labelledby="fund-heading" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="fund-heading" class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $fund->name }} <span class="font-normal text-gray-500 dark:text-gray-400">· kept by {{ $keeps ? 'you' : $fund->custodian->name }}</span></h2>
                @if ($keeps)
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.button type="button" variant="dark" size="sm" wire:click="startExpense"><x-icon name="plus" class="h-4 w-4" /> Record expense</x-ui.button>
                        <x-ui.button type="button" size="sm" wire:click="requestReplenishment"
                            :disabled-reason="match (true) { $pending !== null => 'A request is waiting for Accounting.', $notAsked <= 0 => 'No expenses to replenish yet.', default => null }"
                            wire:confirm="Ask Accounting to replenish {{ $peso($notAsked) }}?"><x-icon name="arrow-path" class="h-4 w-4" /> Ask to replenish{{ $notAsked > 0 ? ' '.$peso($notAsked) : '' }}</x-ui.button>
                    </div>
                @endif
            </div>
            @error('replenish') <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror
            @error('expense') <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror

            <x-ui.metric-strip :count="3">
                <x-ui.metric icon="wallet" label="Fund" :value="$peso((float) $fund->amount)" :hint="$fund->office?->name ?? 'No office'" />
                <x-ui.metric icon="banknotes" label="Spent, not yet back" :value="$peso($spent)" :hint="$pending ? $peso((float) $pending->amount).' waiting for Accounting' : 'nothing asked yet'" />
                <x-ui.metric icon="banknotes" label="Cash on hand" :value="$peso($fund->amount - $spent)" :hint="'of '.$peso((float) $fund->amount)" :tone="$fund->is_active && $fund->amount - $spent < (float) $fund->amount * 0.25 ? 'amber' : 'gray'" />
            </x-ui.metric-strip>

            <x-ui.card title="Expenses not yet replenished" description="Each with its receipt. Once Accounting releases the replenishment, they leave this list and the cash is back.">
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th class="ui-th">Date</th><th class="ui-th">Paid to</th><th class="ui-th">For</th><th class="ui-th text-right">Amount</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            @forelse ($this->expenses as $expense)
                                <tr wire:key="expense-{{ $expense->id }}">
                                    <td class="ui-td whitespace-nowrap">{{ $expense->spent_on->format('M j, Y') }}</td>
                                    <td class="ui-td">{{ $expense->paid_to }}</td>
                                    <td class="ui-td">
                                        <p>{{ $expense->purpose }}</p>
                                        @if ($expense->petty_cash_replenishment_id !== null)
                                            <p class="text-xs text-gray-500 dark:text-gray-400">In the request waiting for Accounting</p>
                                        @endif
                                    </td>
                                    <td class="ui-td text-right tabular-nums">{{ $peso((float) $expense->amount) }}</td>
                                    <td class="ui-td text-right whitespace-nowrap">
                                        <a href="{{ route('petty-cash.receipt', $expense) }}" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Receipt</a>
                                        @if ($keeps && $expense->petty_cash_replenishment_id === null)
                                            <x-ui.row-delete wire:click="deleteExpense({{ $expense->id }})" wire:confirm="Remove this {{ $peso((float) $expense->amount) }} expense ({{ $expense->paid_to }})?">Remove</x-ui.row-delete>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="ui-td py-8 text-center">{{ $keeps ? 'Nothing spent yet. Record each payment with its receipt.' : 'Nothing spent since the last replenishment.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            @if ($this->requests->isNotEmpty())
                <x-ui.card title="Replenishments">
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($this->requests as $request)
                            <li wire:key="request-{{ $request->id }}" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                <span class="min-w-0">
                                    <span class="block font-medium text-gray-800 tabular-nums dark:text-gray-100">{{ $peso((float) $request->amount) }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        Asked {{ $request->created_at->format('M j, Y') }}
                                        @if ($request->decider) · {{ $request->status === \App\Enums\ReplenishmentStatus::Released ? 'released' : 'returned' }} by {{ $request->decider->name }}, {{ $request->decided_at?->format('M j') }} @endif
                                        @if ($request->decision_note) · "{{ $request->decision_note }}" @endif
                                    </span>
                                </span>
                                <x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </section>
    @elseif ($this->funds->isNotEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">Choose a fund to see its expenses.</p>
    @endif

    @if ($fundFormOpen)
        <x-ui.modal :title="$editingFundId ? 'Edit fund' : 'Add fund'" close="$set('fundFormOpen', false)" max-width="md">
            <form id="fund-form" wire:submit="saveFund" class="space-y-4">
                <x-ui.input label="Name" wire:model="fundName" placeholder="e.g. Head Office petty cash" maxlength="150" />
                <x-ui.select label="Office (optional)" wire:model="fundOfficeId">
                    <option value="">Not one office</option>
                    @foreach ($this->offices as $office)
                        <option value="{{ $office->id }}">{{ $office->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Custodian" wire:model="custodianId" searchable>
                    <option value="">Who keeps the cash</option>
                    @foreach ($this->people as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Amount (₱)" type="number" step="0.01" min="100" wire:model="fundAmount" hint="The fixed amount the fund is topped back up to." />
                @if ($editingFundId)
                    <x-ui.switch id="fund-active" wire:model="fundActive" label="Fund in use" description="Off: the fund is closed. Its records stay." />
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button form="fund-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($expenseFormOpen)
        <x-ui.modal title="Record expense" close="$set('expenseFormOpen', false)" max-width="md">
            <form id="expense-form" wire:submit="saveExpense" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input label="Date" type="date" wire:model="spentOn" :max="today()->toDateString()" />
                    <x-ui.input label="Amount (₱)" type="number" step="0.01" min="0.01" wire:model="expenseAmount" :hint="$fund ? 'Cash on hand: '.$peso($fund->cashOnHand()) : null" />
                </div>
                <x-ui.input label="Paid to" wire:model="paidTo" placeholder="e.g. National Book Store" maxlength="150" />
                <x-ui.textarea label="What it was for" wire:model="expensePurpose" rows="2" maxlength="500" placeholder="e.g. Bond paper and ink for the payroll printouts" />
                <x-ui.file-input label="Receipt" model="receipt" id="petty-cash-receipt" accept="image/jpeg,image/png,application/pdf" hint="A photo or a PDF of the official receipt, up to 5 MB." />
            </form>
            <x-slot:footer>
                <x-ui.button form="expense-form" wire:target="receipt, saveExpense">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($returningId)
        <x-ui.modal title="Return the request" close="$set('returningId', null)" max-width="md">
            <form id="return-form" wire:submit="returnRequest" class="space-y-4">
                <x-ui.textarea label="What to fix (sent to the custodian)" wire:model="decisionNote" rows="3" maxlength="500" placeholder="e.g. The Oct 3 receipt is unreadable; please attach a clearer photo." />
            </form>
            <x-slot:footer>
                <x-ui.button form="return-form">Return</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
