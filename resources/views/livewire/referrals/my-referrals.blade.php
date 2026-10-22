<div class="space-y-6">
    @php $bonus = '₱'.number_format($terms['bonus_amount']); @endphp

    <x-ui.card title="Refer a Friend" :description="'Know someone who would make a good guard or office staff? Refer them. If they\'re hired and still work here '.$terms['days_to_stay'].' days after their hire date, you get '.$bonus.' on your next payslip.'">
        <x-slot:actions>
            <x-ui.button type="button" size="sm" wire:click="start"><x-icon name="user-plus" class="h-4 w-4" /> Refer someone</x-ui.button>
        </x-slot:actions>

        @error('referral') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->referrals as $referral)
                <li wire:key="referral-{{ $referral->id }}" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                    <span class="min-w-0">
                        <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $referral->applicant_name }}{{ $referral->position ? ' · '.$referral->position->label() : '' }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            Referred {{ $referral->created_at->format('M j, Y') }}
                            @if ($referral->status === \App\Enums\ReferralStatus::Hired)
                                · ₱{{ number_format((float) $referral->bonus_amount, 2) }} on your payslip after {{ $referral->bonus_due_on?->format('M j, Y') }}, if they still work here then
                            @elseif ($referral->status === \App\Enums\ReferralStatus::Paid && $referral->paidInRun)
                                · ₱{{ number_format((float) $referral->bonus_amount, 2) }} paid on {{ $referral->paidInRun->periodLabel() }}
                            @elseif ($referral->decision_note)
                                · HR: {{ $referral->decision_note }}
                            @endif
                        </span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        <x-ui.badge :tone="$referral->status->tone()">{{ $referral->status->label() }}</x-ui.badge>
                        @if ($referral->status === \App\Enums\ReferralStatus::Submitted)
                            <x-ui.row-delete wire:click="withdraw({{ $referral->id }})" wire:confirm="Withdraw the referral of {{ $referral->applicant_name }}?">Withdraw</x-ui.row-delete>
                        @endif
                    </span>
                </li>
            @empty
                <li class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No referrals yet.</li>
            @endforelse
        </ul>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal title="Refer someone" close="$set('formOpen', false)" max-width="md">
            <form id="referral-form" wire:submit="submit" class="space-y-4">
                <x-ui.input label="Their full name" wire:model="applicantName" placeholder="e.g. Juan Dela Cruz" autocapitalize="words" maxlength="150"
                    x-on:input="$el.value = $el.value.replace(/[^\p{L}\p{M} .,'-]/gu, '')" />
                <x-ui.input label="Their mobile number" wire:model="applicantPhone" type="tel" inputmode="tel" placeholder="0917 123 4567" maxlength="20"
                    x-on:input="$el.value = $el.value.replace(/[^\d\s()+-]/g, '')" hint="HR calls them on this number." />
                <x-ui.select label="Job (optional)" wire:model="position">
                    <option value="">Any</option>
                    @foreach (\App\Enums\Position::offered() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.textarea label="Note to HR (optional)" wire:model="note" rows="2" maxlength="500" placeholder="e.g. My cousin, a licensed guard with 3 years at a bank" />
                <p class="text-xs text-gray-500 dark:text-gray-400">Tell them first that you're giving HR their name and number, and that HR will contact them.</p>
            </form>
            <x-slot:footer>
                <x-ui.button form="referral-form">Send to HR</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
