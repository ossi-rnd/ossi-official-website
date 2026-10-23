@php $peso = fn (float|string $amount): string => '₱'.number_format((float) $amount, 2); @endphp

<div class="mx-auto max-w-3xl space-y-6">
    {{-- The usual claim: a reliever's fare. Reliefs done lately with nothing claimed, one tap from the form. --}}
    @if ($this->reliefsToClaim->isNotEmpty())
        <x-ui.card title="Reliefs to claim" description="Reliefs done in the last {{ \App\Models\Reimbursement::fileWithinDays() }} days with no fare claimed. If the reliever paid to get there, claim it here.">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($this->reliefsToClaim as $relief)
                    <li wire:key="relief-{{ $relief->id }}" class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0 text-sm">
                            <p class="text-gray-800 dark:text-gray-100"><x-person-link :user="$relief->reliever" /> <span class="text-xs text-gray-500">· {{ $relief->duty_date->format('D, M j') }}</span></p>
                            <p class="truncate text-xs text-gray-500">{{ $relief->placeLabel() }}, for {{ $relief->user->name }}</p>
                        </div>
                        <button type="button" wire:click="startForRelief({{ $relief->id }})" class="shrink-0 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Claim fare</button>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card title="Deployment Costs" description="What a guard paid for a deployment: a fare to a far post, a reliever's ride. File it with the receipt; once the President or Vice President approves it, it's added to the guard's next payslip, not taxed. Your own costs go on My Reimbursements.">
        <x-slot:actions>
            <x-ui.button type="button" wire:click="start">File for a guard</x-ui.button>
        </x-slot:actions>

        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->claims as $claim)
                <li wire:key="claim-{{ $claim->id }}" class="flex items-start justify-between gap-3 px-5 py-4">
                    <div class="min-w-0 flex-1 text-sm">
                        <p class="text-gray-800 dark:text-gray-100"><x-person-link :user="$claim->user" /> <span class="text-xs text-gray-500">· {{ $claim->spent_on->format('M j, Y') }}</span></p>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $claim->deployment_for }}</p>
                        <p class="mt-0.5 text-gray-600 dark:text-gray-300">{{ $claim->purpose }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            <a href="{{ route('reimbursements.receipt', $claim) }}" target="_blank" class="font-medium text-gray-600 underline decoration-gray-300 underline-offset-2 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400">Receipt</a>
                            @if ($claim->status === \App\Enums\ReimbursementStatus::Paid && $claim->paidInRun) · Paid on their {{ $claim->paidInRun->periodLabel() }} payslip @endif
                            @if ($claim->status === \App\Enums\ReimbursementStatus::Rejected) · Rejected: {{ $claim->decision_note }} @endif
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-sm font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $peso($claim->amount) }}</p>
                        <x-ui.badge class="mt-1" :tone="$claim->status->tone()">{{ $claim->status->label() }}</x-ui.badge>
                        @if ($claim->status === \App\Enums\ReimbursementStatus::Pending)
                            <button type="button" wire:click="cancel({{ $claim->id }})" wire:confirm="Cancel this claim?" class="mt-1 block w-full text-right text-xs font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Cancel</button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-5 py-8 text-center text-sm text-gray-500">No deployment costs filed yet.</li>
            @endforelse
        </ul>
    </x-ui.card>

    @if ($filing)
        <x-ui.modal title="File a guard's deployment cost" close="closeForm" max-width="lg">
            <form id="deployment-form" wire:submit="file" class="space-y-4">
                {{-- The guard: search by name or employee number; suggestions before typing. --}}
                <div>
                    <p class="ui-label">Guard</p>
                    @if ($guard = $this->chosen)
                        <div class="mt-1 flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ $guard->name }}</span>
                                <span class="block text-xs text-gray-500">{{ $guard->employeeProfile?->assignmentLabel() ?? '—' }}</span>
                            </span>
                            <button type="button" wire:click="changeGuard" class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Change</button>
                        </div>
                    @else
                        <x-ui.input type="search" wire:model.live.debounce.300ms="personSearch" placeholder="Search by name or employee no." aria-label="Search the guard by name or employee number" class="mt-1" />
                        @if ($this->candidates->isNotEmpty())
                            @if (mb_strlen(trim($personSearch)) < 2)<p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Suggested, or search above:</p>@endif
                            <ul class="mt-1 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
                                @foreach ($this->candidates as $candidate)
                                    <li wire:key="candidate-{{ $candidate->id }}">
                                        <button type="button" wire:click="choose({{ $candidate->id }})" class="w-full px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/5">
                                            <span class="block text-sm text-gray-800 dark:text-gray-100">{{ $candidate->name }}</span>
                                            <span class="block text-xs text-gray-500">{{ $candidate->employeeProfile?->assignmentLabel() ?? '—' }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif (mb_strlen(trim($personSearch)) >= 2)
                            <p class="mt-1 text-xs text-gray-500">No guard you deploy by that name or number.</p>
                        @endif
                        @error('guardId') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    @endif
                </div>

                @if ($reliefId)
                    <p class="text-xs text-gray-500 dark:text-gray-400">The reliever's fare for this relief: linked to it on Relievers, and claimed once.</p>
                @endif
                @error('relief') <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror
                <x-ui.input label="Which deployment" wire:model="deploymentFor" maxlength="255" placeholder="e.g. Relief at Metrobank – Dasmariñas, Main Entrance" />
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input label="Date spent" type="date" wire:model="spentOn" :max="today()->toDateString()" />
                    <x-ui.input label="Amount (₱)" type="number" step="0.01" min="1" :max="\App\Models\Reimbursement::maxAmount(deployment: true)" wire:model="amount" />
                </div>
                <x-ui.textarea label="What it was for" wire:model="purpose" rows="2" maxlength="1000" :placeholder="\App\Enums\ReimbursementCategory::Deployment->example()" />
                <div>
                    <label for="deployment-receipt" class="ui-label">Receipt <span class="font-normal text-gray-500">(a photo or a PDF)</span></label>
                    <input id="deployment-receipt" type="file" wire:model="receipt" accept="image/jpeg,image/png,application/pdf"
                        class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border file:border-gray-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-50 dark:text-gray-300 dark:hover:file:bg-gray-700 dark:file:border-gray-700 dark:file:bg-gray-800 dark:file:text-gray-200">
                    <p wire:loading wire:target="receipt" class="mt-1 text-xs text-gray-500">Uploading…</p>
                    @error('receipt') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                @include('livewire.partials.acknowledgement', ['lines' => \App\Models\Reimbursement::acknowledgementLines($ackLocale, forGuard: true), 'model' => 'acknowledges', 'id' => 'deployment-acknowledge', 'labelKey' => 'certify'])
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="ghost" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button form="deployment-form" wire:target="file,receipt">Send for approval</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
