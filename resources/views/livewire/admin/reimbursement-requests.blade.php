@php $peso = fn (float|string $amount): string => '₱'.number_format((float) $amount, 2); @endphp

<div>
    <x-ui.card title="Reimbursements" description="What office staff spent for the company, with the receipt. The President or Vice President approves; approved ones are added to the next payslip, not taxed. Accounting sees them all.">
        <x-slot:actions>
            <x-ui.select wire:model.live="statusFilter" aria-label="Show">
                <option value="pending">Waiting for approval</option>
                <option value="approved">Approved and paid</option>
                <option value="all">All</option>
            </x-ui.select>
        </x-slot:actions>

        <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-50" wire:target="statusFilter">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Employee</th><th class="ui-th">For</th><th class="ui-th">Spent</th><th class="ui-th text-right">Amount</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->requests as $item)
                        <tr wire:key="request-{{ $item->id }}">
                            <td class="ui-td"><p><x-person-link :user="$item->user" /></p><p class="text-xs text-gray-500">{{ $item->user->employeeProfile?->position?->label() ?? 'Office staff' }}</p>@if ($item->filedForThem())<p class="text-xs text-gray-500">Filed by {{ $item->filer->name ?? 'Operations' }}</p>@endif</td>
                            <td class="ui-td"><p class="text-gray-800 dark:text-gray-100">{{ $item->category->short() }}</p><p class="max-w-xs truncate text-xs text-gray-500" title="{{ $item->purpose }}">{{ $item->purpose }}</p></td>
                            <td class="ui-td whitespace-nowrap">{{ $item->spent_on->format('M j, Y') }}</td>
                            <td class="ui-td text-right font-semibold whitespace-nowrap text-gray-800 tabular-nums dark:text-gray-100">{{ $peso($item->amount) }}</td>
                            <td class="ui-td">
                                <x-ui.badge :tone="$item->status->tone()">{{ $item->status->label() }}</x-ui.badge>
                                @if ($item->paidInRun)<span class="mt-1 block text-xs text-gray-500">{{ $item->paidInRun->periodLabel() }}</span>@endif
                            </td>
                            <td class="ui-td text-right"><button type="button" wire:click="open({{ $item->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Open</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-td py-8 text-center">{{ $statusFilter === 'pending' ? 'Nothing waiting for approval.' : 'No reimbursements here.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>{{ $this->requests->links() }}</x-slot:footer>
    </x-ui.card>

    {{-- The limits, company policy: Accounting sets them here (audited); the forms show the same numbers. --}}
    @php $limits = \App\Models\Reimbursement::limits(); @endphp
    <x-ui.card class="mt-6" title="Limits" description="Company policy, not law. The forms show these same numbers, and every change is in the audit log.">
        @if ($this->canEditLimits())
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="editLimits">Change</x-ui.button>
            </x-slot:actions>
        @endif
        <dl class="grid grid-cols-1 gap-4 px-6 py-5 text-sm sm:grid-cols-3">
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Receipts from the last</dt><dd class="mt-0.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $limits['file_within_days'] }} days</dd></div>
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Most per staff reimbursement</dt><dd class="mt-0.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100">₱{{ number_format($limits['max_amount']) }}</dd></div>
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Most per guard's deployment claim</dt><dd class="mt-0.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100">₱{{ number_format($limits['deployment_max_amount']) }}</dd></div>
        </dl>
    </x-ui.card>

    @if ($editingLimits)
        <x-ui.modal title="Change the limits" close="$set('editingLimits', false)" max-width="md">
            <form id="limits-form" wire:submit="saveLimits" class="space-y-4">
                <x-ui.input label="Receipts from the last (days)" type="number" min="7" max="365" wire:model="fileWithinDays" hint="Older receipts aren't accepted: file it while it's fresh." />
                <x-ui.input label="Most per staff reimbursement (₱)" type="number" min="100" step="1" wire:model="maxAmount" hint="Office staff's own: fares, fees, supplies. Above this, buy through Accounting first." />
                <x-ui.input label="Most per guard's deployment claim (₱)" type="number" min="100" step="1" wire:model="deploymentMaxAmount" hint="Filed by Operations for a guard: fares to a post or a relief. Usually a few hundred pesos." />
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="ghost" wire:click="$set('editingLimits', false)">Cancel</x-ui.button>
                <x-ui.button form="limits-form" wire:target="saveLimits">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($item = $this->opened)
        @php $pending = $item->status === \App\Enums\ReimbursementStatus::Pending; @endphp
        <x-ui.modal :title="$item->user->name.' · '.$peso($item->amount)" close="close" max-width="lg">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-xs text-gray-500">For</dt><dd class="text-gray-800 dark:text-gray-100">{{ $item->category->label() }} · {{ $item->spent_on->format('M j, Y') }}</dd></div>
                @if ($item->deployment_for)<div><dt class="text-xs text-gray-500">Deployment</dt><dd class="text-gray-800 dark:text-gray-100">{{ $item->deployment_for }}</dd></div>@endif
                @if ($item->filedForThem())<div><dt class="text-xs text-gray-500">Filed by</dt><dd class="text-gray-800 dark:text-gray-100">{{ $item->filer->name ?? 'Operations' }}, for the guard, who's paid back on their payslip</dd></div>@endif
                <div><dt class="text-xs text-gray-500">Purpose</dt><dd class="whitespace-pre-line text-gray-800 dark:text-gray-100">{{ $item->purpose }}</dd></div>
                @if ($item->awayLog)<div><dt class="text-xs text-gray-500">Office Away trip</dt><dd class="text-gray-800 dark:text-gray-100">{{ $item->awayLog->reason->label() }} · {{ $item->awayLog->left_at->format('M j, g:i A') }}{{ $item->awayLog->note ? ' · '.$item->awayLog->note : '' }}</dd></div>@endif
                <div><dt class="text-xs text-gray-500">Receipt</dt><dd><a href="{{ route('reimbursements.receipt', $item) }}" target="_blank" class="inline-flex items-center gap-1 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300"><x-icon name="arrow-top-right-on-square" class="h-4 w-4" /> {{ $item->receipt_name ?? 'Open the receipt' }}</a>@include('livewire.partials.acknowledged', ['record' => $item])</dd></div>
                @unless ($pending)
                    <div><dt class="text-xs text-gray-500">Decision</dt><dd class="text-gray-800 dark:text-gray-100">{{ $item->status->label() }} · {{ $item->decider?->name ?? '—' }}{{ $item->decided_at ? ', '.$item->decided_at->format('M j') : '' }}{{ $item->decision_note ? ' · '.$item->decision_note : '' }}</dd></div>
                @endunless
            </dl>
            @if ($pending && $this->canDecide() && $item->user_id !== auth()->id())
                <div class="mt-4"><x-ui.input label="Note (required to reject)" wire:model="decisionNote" placeholder="e.g. Please attach the official receipt, not the order slip" /></div>
            @endif
            @if ($pending && $this->canDecide() && $item->user_id !== auth()->id())
                <x-slot:footer>
                    <x-ui.button type="button" variant="secondary" wire:click="reject" wire:target="reject">Reject</x-ui.button>
                    <x-ui.button type="button" wire:click="approve" wire:target="approve">Approve</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.modal>
    @endif
</div>
