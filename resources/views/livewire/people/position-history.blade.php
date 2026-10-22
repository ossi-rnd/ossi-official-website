@php $profile = $this->profile; @endphp

<div>
    <x-ui.card title="Position history" :description="'Now: '.($profile->position?->label() ?? 'no position').'. Pay uses the position held on each cut-off\'s start date.'">
        <x-slot:actions>
            @if ($canManage)
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="start">Promote or change position</x-ui.button>
            @endif
        </x-slot:actions>

        @if ($this->changes->isEmpty())
            <p class="px-5 py-6 text-center text-sm text-gray-500">No changes yet: {{ $profile->position?->label() ?? 'no position' }} since hired.</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($this->changes as $change)
                    <li wire:key="position-{{ $change->id }}" class="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
                        <div class="min-w-0 text-sm">
                            <p class="text-gray-800 dark:text-gray-100">
                                {{ $change->from_position?->label() ?? '—' }} → <span class="font-medium">{{ $change->to_position->label() }}</span>
                                <span class="text-xs text-gray-500"> · {{ $change->kindLabel() }}</span>
                            </p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                From {{ $change->effective_on->format('M j, Y') }}
                                @if (! $change->applied_at)<span class="ml-1 inline-flex items-center gap-1"><span class="size-1.5 rounded-full bg-blue-500"></span>starts on its day</span>@endif
                                · {{ $change->recorder?->name ?? '—' }}
                            </p>
                            @if ($change->reason)<p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $change->reason }}</p>@endif
                        </div>
                        @if ($canManage && ! $change->applied_at)
                            <button type="button" wire:click="cancel({{ $change->id }})" wire:confirm="Cancel this change of position?" class="shrink-0 text-xs font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Cancel</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    @if ($changing)
        @php $rate = $this->rateThen(); @endphp
        <x-ui.modal :title="'Promote or change position · '.$profile->name" close="$set('changing', false)" max-width="lg">
            <form id="position-form" wire:submit="save" class="space-y-4">
                <fieldset>
                    <legend class="ui-label">What it is</legend>
                    <div class="mt-1 grid grid-cols-2 gap-1 rounded-md border border-gray-300 p-1 dark:border-gray-700">
                        @foreach ([\App\Models\PositionChange::PROMOTION => 'Promotion', \App\Models\PositionChange::TRANSFER => 'Change of position'] as $value => $label)
                            <label class="flex min-h-9 cursor-pointer items-center justify-center rounded px-3 text-sm font-medium has-checked:bg-gray-800 has-checked:text-white dark:has-checked:bg-gray-100 dark:has-checked:text-gray-800">
                                <input type="radio" wire:model="kind" value="{{ $value }}" class="sr-only">{{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <x-ui.select label="New position" wire:model.live="toPosition">
                    <option value="">Choose…</option>
                    @foreach ($positions as $position)
                        @unless ($position === $profile->position)<option value="{{ $position->value }}">{{ $position->label() }}</option>@endunless
                    @endforeach
                </x-ui.select>
                <x-ui.input label="From (a cut-off start: the 1st or the 16th)" type="date" wire:model.live="effectiveOn" />
                @if ($toPosition !== '' && $effectiveOn !== '')
                    <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ $rate !== null ? 'The position pays ₱'.number_format($rate, 2).' a day then' : 'That position has no company rate yet: Accounting is told to set it' }}. A personal rate, if they have one, still applies; Accounting is told to review it.
                        @if (\Carbon\CarbonImmutable::parse($effectiveOn)->isPast() && ! \Carbon\CarbonImmutable::parse($effectiveOn)->isToday()) Back-dated: the cut-offs already paid are suggested as corrections on the next payroll. @endif
                    </p>
                @endif
                <x-ui.textarea label="Why" wire:model="reason" rows="2" maxlength="1000" placeholder="e.g. Passed the shift-in-charge assessment; recommended by the detachment commander." />
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="ghost" wire:click="$set('changing', false)">Cancel</x-ui.button>
                <x-ui.button form="position-form" wire:target="save">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
