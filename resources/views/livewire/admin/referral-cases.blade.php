<div class="space-y-6">
    @php
        $filters = ['open' => 'Open', ...collect(\App\Enums\ReferralStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()];
        $today = \Carbon\CarbonImmutable::today();
    @endphp

    <x-ui.card title="Referrals" :description="'Employees refer people to hire. Mark each hired (pick their record) or not hired. The referrer gets ₱'.number_format($terms['bonus_amount']).' on their payslip once the hire has stayed '.$terms['days_to_stay'].' days.'">
        <x-slot:actions>
            <x-ui.select wire:model.live="statusFilter" aria-label="Show">
                @foreach ($filters as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.button type="button" variant="secondary" size="sm" wire:click="editTerms"><x-icon name="cog" class="h-4 w-4" /> Bonus terms</x-ui.button>
        </x-slot:actions>

        @error('referral') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Referred</th><th class="ui-th">By</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->referrals as $referral)
                        <tr wire:key="referral-{{ $referral->id }}">
                            <td class="ui-td">
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ $referral->applicant_name }}</p>
                                <p class="text-xs text-gray-500">{{ $referral->applicant_phone }}{{ $referral->position ? ' · '.$referral->position->label() : '' }} · {{ $referral->created_at->format('M j, Y') }}</p>
                                @if ($referral->note)<p class="mt-0.5 text-xs text-gray-500">"{{ $referral->note }}"</p>@endif
                            </td>
                            <td class="ui-td">{{ $referral->referrer->name }}</td>
                            <td class="ui-td">
                                <x-ui.badge :tone="$referral->status->tone()">{{ $referral->status->label() }}</x-ui.badge>
                                @if ($referral->status === \App\Enums\ReferralStatus::Hired)
                                    <p class="mt-1 text-xs text-gray-500">{{ $referral->hire?->name }} · ₱{{ number_format((float) $referral->bonus_amount, 2) }} due {{ $referral->bonus_due_on?->format('M j, Y') }}</p>
                                    @if ($referral->hire && ! $referral->hire->employment_status->isActive())
                                        <p class="text-xs text-amber-700 dark:text-amber-400">The hire no longer works here</p>
                                    @endif
                                @elseif ($referral->decision_note)
                                    <p class="mt-1 text-xs text-gray-500">{{ $referral->decision_note }}</p>
                                @endif
                            </td>
                            <td class="ui-td text-right whitespace-nowrap">
                                @if ($referral->status === \App\Enums\ReferralStatus::Submitted)
                                    <button type="button" wire:click="decide({{ $referral->id }}, 'not_hired')" class="font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100">Not hired</button>
                                    <button type="button" wire:click="decide({{ $referral->id }}, 'hired')" class="ml-3 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Hired</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-td py-8 text-center">No referrals here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($deciding)
        <x-ui.modal :title="($decision === 'hired' ? 'Hired: ' : 'Not hired: ').$deciding->applicant_name" close="$set('decidingId', null)" max-width="md">
            <form id="referral-decision-form" wire:submit="saveDecision" class="space-y-4">
                @if ($decision === 'hired')
                    <div>
                        <x-ui.select label="Their record" wire:model="hireId" searchable>
                            <option value="">Choose the hire</option>
                            @foreach ($this->recentHires as $hire)
                                <option value="{{ $hire->id }}">{{ $hire->name }} · {{ $hire->employee_number }} · hired {{ $hire->hire_date?->format('M j, Y') }}</option>
                            @endforeach
                        </x-ui.select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Add them on Employees first. Listed: hired in the last 6 months, not linked to a referral yet.</p>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $deciding->referrer->name }} gets ₱{{ number_format($terms['bonus_amount'], 2) }} on their payslip once the hire has stayed {{ $terms['days_to_stay'] }} days from their hire date.</p>
                @else
                    <x-ui.textarea label="Why (sent to {{ $deciding->referrer->name }})" wire:model="decisionNote" rows="2" maxlength="500" placeholder="e.g. Didn't pass the neuro-psychiatric exam." />
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button form="referral-decision-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($termsOpen)
        <x-ui.modal title="Referral bonus terms" close="$set('termsOpen', false)" max-width="md">
            <form id="referral-terms-form" wire:submit="saveTerms" class="space-y-4">
                <x-ui.input label="Bonus (₱)" type="number" min="0" max="100000" step="1" wire:model="bonusAmount" hint="Paid on the referrer's payslip. 0: referrals are tracked, no bonus." />
                <x-ui.input label="Days the hire must stay" type="number" min="0" max="365" step="1" wire:model="daysToStay" hint="Counted from the hire date. 0: paid on the next payslip after hiring." />
                <p class="text-xs text-gray-500 dark:text-gray-400">Referrals already hired keep the terms they were hired under.</p>
            </form>
            <x-slot:footer>
                <x-ui.button form="referral-terms-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
