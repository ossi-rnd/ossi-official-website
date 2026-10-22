@php
    $chosen = \App\Enums\ReimbursementCategory::tryFrom($category) ?? \App\Enums\ReimbursementCategory::Other;
    $peso = fn (float|string $amount): string => '₱'.number_format((float) $amount, 2);
@endphp

<div class="mx-auto max-w-3xl space-y-6">
    <x-ui.card title="My Reimbursements" :description="$this->canFile()
        ? 'Money you spent for the company: a fare to SSS, a notarial fee, office supplies, a deployment. Attach the receipt; once the President or Vice President approves it, it\'s added to your next payslip, not taxed.'
        : 'What you paid for a deployment, claimed for you by Operations. Once the President or Vice President approves it, it\'s added to your next payslip, not taxed.'">
        @if ($this->canFile())
            <x-slot:actions>
                <x-ui.button type="button" wire:click="start">File a reimbursement</x-ui.button>
            </x-slot:actions>
        @endif

        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->reimbursements as $item)
                <li wire:key="reimbursement-{{ $item->id }}" class="flex items-start justify-between gap-3 px-5 py-4">
                    <div class="min-w-0 flex-1 text-sm">
                        <p class="text-gray-800 dark:text-gray-100"><span class="font-medium">{{ $item->category->short() }}</span> <span class="text-xs text-gray-500">· {{ $item->spent_on->format('M j, Y') }}</span></p>
                        @if ($item->deployment_for || $item->filedForThem())
                            <p class="mt-0.5 text-xs text-gray-500">{{ collect([$item->deployment_for, $item->filedForThem() ? 'Filed for you by '.($item->filer->name ?? 'Operations') : null])->filter()->implode(' · ') }}</p>
                        @endif
                        <p class="mt-0.5 text-gray-600 dark:text-gray-300">{{ $item->purpose }}</p>
                        @if ($item->awayLog)<p class="mt-0.5 text-xs text-gray-500">Trip: {{ $item->awayLog->reason->label() }} · {{ $item->awayLog->left_at->format('M j') }}{{ $item->awayLog->note ? ' · '.$item->awayLog->note : '' }}</p>@endif
                        <p class="mt-1 text-xs text-gray-500">
                            <a href="{{ route('reimbursements.receipt', $item) }}" target="_blank" class="font-medium text-gray-600 underline decoration-gray-300 underline-offset-2 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400">Receipt</a>
                            @if ($item->status === \App\Enums\ReimbursementStatus::Paid && $item->paidInRun) · Paid on your {{ $item->paidInRun->periodLabel() }} payslip @endif
                            @if ($item->status === \App\Enums\ReimbursementStatus::Rejected) · Rejected: {{ $item->decision_note }} @endif
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-sm font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $peso($item->amount) }}</p>
                        <x-ui.badge class="mt-1" :tone="$item->status->tone()">{{ $item->status->label() }}</x-ui.badge>
                        @if ($item->status === \App\Enums\ReimbursementStatus::Pending && ! $item->filedForThem())
                            <button type="button" wire:click="cancel({{ $item->id }})" wire:confirm="Cancel this reimbursement?" class="mt-1 block w-full text-right text-xs font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Cancel</button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-5 py-8 text-center text-sm text-gray-500">No reimbursements yet.</li>
            @endforelse
        </ul>
    </x-ui.card>

    @if ($filing)
        <x-ui.modal title="File a reimbursement" close="$set('filing', false)" max-width="lg">
            <form id="reimbursement-form" wire:submit="file" class="space-y-4">
                <x-ui.select label="What for" wire:model.live="category">
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                @if ($chosen === \App\Enums\ReimbursementCategory::Deployment)
                    <x-ui.input label="Which deployment" wire:model="deploymentFor" maxlength="255" placeholder="e.g. Relief at Metrobank – Dasmariñas, for Romeo Ocampo" />
                @endif
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input label="Date spent" type="date" wire:model="spentOn" :max="today()->toDateString()" />
                    <x-ui.input label="Amount (₱)" type="number" step="0.01" min="1" :max="\App\Models\Reimbursement::maxAmount()" wire:model="amount" />
                </div>
                <x-ui.textarea label="What it was for, and for whom" wire:model="purpose" rows="2" maxlength="1000" :placeholder="$chosen->example()" />
                @if ($this->trips->isNotEmpty())
                    <x-ui.select label="From an Office Away trip (optional)" wire:model="awayLogId">
                        <option value="">None</option>
                        @foreach ($this->trips as $trip)
                            <option value="{{ $trip->id }}">{{ $trip->left_at->format('M j') }} · {{ $trip->reason->label() }}{{ $trip->note ? ' · '.\Illuminate\Support\Str::limit($trip->note, 40) : '' }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
                <div>
                    <label for="reimbursement-receipt" class="ui-label">Receipt <span class="font-normal text-gray-500">(a photo or a PDF of the official receipt)</span></label>
                    <input id="reimbursement-receipt" type="file" wire:model="receipt" accept="image/jpeg,image/png,application/pdf"
                        class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border file:border-gray-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-50 dark:text-gray-300 dark:hover:file:bg-gray-700 dark:file:border-gray-700 dark:file:bg-gray-800 dark:file:text-gray-200">
                    <p wire:loading wire:target="receipt" class="mt-1 text-xs text-gray-500">Uploading…</p>
                    @error('receipt') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <p class="text-xs text-gray-500">File within {{ \App\Models\Reimbursement::fileWithinDays() }} days of spending it. Up to ₱{{ number_format(\App\Models\Reimbursement::maxAmount()) }} per request; bigger purchases go through Accounting.</p>
                {{-- The usual expense certification, saved with the claim. --}}
                @include('livewire.partials.acknowledgement', ['lines' => \App\Models\Reimbursement::acknowledgementLines($ackLocale), 'model' => 'acknowledges', 'id' => 'reimbursement-acknowledge', 'labelKey' => 'certify'])
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="ghost" wire:click="$set('filing', false)">Cancel</x-ui.button>
                <x-ui.button form="reimbursement-form" wire:target="file,receipt">Send for approval</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
