@php
    $current = $this->current;
    $handler = $this->handler();
@endphp

<div class="mx-auto max-w-3xl space-y-6">
    @if ($current)
        {{-- In progress: a timeline of the four dates, the handler's note, and the clearance. --}}
        @php
            $accepted = $current->status === \App\Enums\ResignationStatus::Accepted;
            $today = today();
            $lastDay = $current->effectiveLastDay();
            // Filed → accepted → last day → final pay. "Done" fills the dot; the next one is ringed.
            $steps = [
                ['Filed', $current->created_at->format('M j'), $current->created_at->format('l'), true],
                ['Accepted', $accepted ? $current->decided_at?->format('M j') : 'Waiting', $accepted ? 'by '.($current->decider?->name ?? $current->handlerLabel()) : 'for '.$current->handlerLabel(), $accepted],
                ['Last day', $lastDay->format('M j'), $lastDay->format('l').($accepted && $current->last_day?->ne($current->requested_last_day) ? ' · you asked '.$current->requested_last_day->format('M j') : ''), $lastDay->lt($today)],
                // Released: the amount; before: the due date.
                $current->finalPay?->status === \App\Enums\FinalPayStatus::Released
                    ? ['Final pay', '₱'.number_format((float) $current->finalPay->net, 2), 'released '.$current->finalPay->released_at?->format('M j'), true]
                    : ['Final pay', 'by '.$current->finalPayDue()->format('M j'), 'within 30 days of the last day', $current->isCleared(\App\Enums\ClearanceItem::FinalPay)],
            ];
            $nextStep = collect($steps)->search(fn (array $step) => ! $step[3]);
        @endphp
        <x-ui.card title="Your resignation" :description="'Reason: '.$current->reasonLabel().' · handled by '.$current->handlerLabel()">
            <x-slot:actions>
                {{-- w-fit: on a phone the header stacks, and the badge would stretch to the full width. --}}
                <x-ui.badge :tone="$current->status->tone()" class="w-fit">{{ $accepted ? 'Accepted' : 'Waiting for '.$current->handlerLabel() }}</x-ui.badge>
            </x-slot:actions>

            <ol class="grid grid-cols-2 gap-y-6 px-6 pt-6 pb-2 sm:grid-cols-4">
                @foreach ($steps as $i => [$label, $date, $detail, $done])
                    <li class="relative pr-4">
                        {{-- The line to the next step, filled once this one is done. --}}
                        @if (! $loop->last)
                            <span @class(['absolute top-[7px] left-4 hidden h-0.5 w-full sm:block', 'bg-blue-500' => $done, 'bg-gray-200 dark:bg-gray-700' => ! $done]) aria-hidden="true"></span>
                        @endif
                        <span @class([
                            'relative block h-4 w-4 rounded-full',
                            'bg-blue-500' => $done,
                            'border-2 border-blue-500 bg-white ring-4 ring-blue-500/15 dark:bg-gray-900' => ! $done && $i === $nextStep,
                            'border-2 border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-900' => ! $done && $i !== $nextStep,
                        ]) aria-hidden="true"></span>
                        <p class="mt-3 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p @class(['mt-0.5 text-sm font-semibold', 'text-gray-800 dark:text-gray-100' => $done || $i === $nextStep, 'text-gray-500 dark:text-gray-400' => ! $done && $i !== $nextStep])>{{ $date }}</p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $detail }}</p>
                    </li>
                @endforeach
            </ol>

            @if ($current->complaint)
                {{-- Their just cause is looked into as a complaint case. --}}
                <p class="mx-6 mt-4 text-sm text-gray-700 dark:text-gray-300">
                    {{ ucfirst($current->handlerLabel()) }} opened complaint <span class="font-medium">{{ $current->complaint->reference() }}</span> to look into what happened · {{ $current->complaint->status->label() }} ·
                    <a href="{{ route('complaints') }}" wire:navigate class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">My Complaints</a>
                </p>
            @endif

            @if ($accepted && $current->decision_note)
                {{-- Their note, signed: who wrote it and when. --}}
                <figure class="mx-6 mt-4 mb-2 border-l-2 border-blue-200 pl-4 dark:border-blue-500/40">
                    <blockquote class="text-sm text-gray-700 dark:text-gray-300">“{{ $current->decision_note }}”</blockquote>
                    <figcaption class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $current->decider?->name ?? $current->handlerLabel() }}, {{ $current->handlerLabel() }} · {{ $current->decided_at?->format('M j') }}</figcaption>
                </figure>
            @endif

            @if ($accepted)
                @php
                    $items = collect($current->clearanceItems());
                    $groups = ['You return before your last day' => $items->reject(fn ($item) => $item->fromCompany()), 'The company gives you' => $items->filter(fn ($item) => $item->fromCompany())];
                    $share = $items->isEmpty() ? 0 : (int) round($current->clearedCount() / $items->count() * 100);
                @endphp
                <div class="mt-4 border-t border-gray-100 px-6 py-5 dark:border-gray-800">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Clearance</p>
                        <p class="text-xs text-gray-500 tabular-nums dark:text-gray-400"><span class="font-medium text-gray-700 dark:text-gray-300">{{ $current->clearedCount() }} of {{ $items->count() }}</span> done · {{ $current->handlerLabel() }} ticks them off</p>
                    </div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" aria-hidden="true"><div class="h-full rounded-full bg-green-500 transition-all" style="width: {{ $share }}%"></div></div>

                    <div class="mt-5 grid grid-cols-1 gap-6 sm:grid-cols-[1.4fr_1fr]">
                        @foreach ($groups as $heading => $groupItems)
                            <div>
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                <ul class="mt-2 space-y-2.5 text-sm">
                                    @foreach ($groupItems as $item)
                                        <li class="flex items-start gap-2.5">
                                            @if ($current->isCleared($item))
                                                <x-ui.verdict-dot tone="green" label="Done" class="mt-0.5" />
                                                <span class="text-gray-700 dark:text-gray-300">{{ $item->label() }}</span>
                                            @else
                                                <span class="mt-0.5 h-4 w-4 shrink-0 rounded-full border-2 border-gray-300 dark:border-gray-600" aria-hidden="true"></span>
                                                <span class="text-gray-500 dark:text-gray-400">{{ $item->label() }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <x-slot:footer>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Changed your mind? You can take it back until {{ $current->handlerLabel() }} accepts it.</p>
                        <x-ui.button type="button" variant="danger-outline" size="sm" wire:click="withdraw" wire:target="withdraw" wire:confirm="Withdraw your resignation?">Withdraw</x-ui.button>
                    </div>
                </x-slot:footer>
            @endif
        </x-ui.card>
    @else
        {{-- Filing: Labor Code Art. 300 asks for 30 days' written notice, unless for a just cause. --}}
        @php $step = 'flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100'; $no = 'flex h-5 w-5 items-center justify-center rounded-full bg-gray-100 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300'; @endphp
        <x-ui.card title="Resign" :description="'Your letter goes to '.$handler.' only. You can take it back until it\'s accepted.'">
            <form wire:submit="submit" class="divide-y divide-gray-100 dark:divide-gray-800">
                <div class="px-6 py-5">
                    @include('livewire.partials.resignation-reasons', ['idPrefix' => 'my', 'self' => true, 'number' => 1, 'wide' => true])
                </div>

                {{-- Filled with the earliest day without a waiver; the hint says why, in words
                     (the box shows the browser's date order, e.g. 14/11/2026). --}}
                <div class="space-y-3 px-6 py-5">
                    <h3 class="{{ $step }}"><span class="{{ $no }}">2</span> Your last day</h3>
                    <div class="sm:w-1/2">
                        <x-ui.input label="Last day you'll work" type="date" wire:model.live="lastDay" :min="today()->toDateString()"
                            :hint="$this->justCauseTicked() ? 'With a just cause, it can be today.' : 'The law asks for 30 days\' notice: '.today()->addDays(\App\Models\Resignation::NOTICE_DAYS)->format('l, M j').' or later. Earlier needs '.$handler.'\'s waiver.'" />
                    </div>
                    @if ($this->needsWaiver())
                        <x-ui.checkbox id="resignation-waiver" wire:model="asksWaiver" label="Ask {{ $handler }} to waive the rest of the 30-day notice"
                            :description="'Your last day is less than 30 days away. Without a waiver, the earliest is '.today()->addDays(\App\Models\Resignation::NOTICE_DAYS)->format('M j, Y').'.'" />
                    @endif
                    @error('lastDay') <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror
                </div>

                <div class="space-y-3 px-6 py-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="{{ $step }}"><span class="{{ $no }}">3</span> Your letter</h3>
                        {{-- Filled from steps 1 and 2; asks before replacing what they already wrote. --}}
                        <x-ui.button type="button" variant="secondary" size="sm" wire:target="useSampleLetter"
                            x-on:click="if ($wire.letter.trim() === '' || confirm('Replace what you wrote with a sample letter?')) $wire.useSampleLetter()">Use a sample letter</x-ui.button>
                    </div>
                    <x-ui.textarea id="letter" label="In your own words, addressed to {{ $handler }}" wire:model="letter" rows="6" maxlength="5000" placeholder="To {{ $handler }}: I am resigning from my position, effective…" />
                </div>

                {{-- Short and specific, so it gets read; saved word for word with the time (acknowledgement).
                     It includes the written authorization (Art. 113) to deduct what's owed from final pay. --}}
                <div class="space-y-3 px-6 py-5">
                    <h3 class="{{ $step }}"><span class="{{ $no }}">4</span> Before you send</h3>
                    @include('livewire.partials.acknowledgement', ['lines' => \App\Models\Resignation::acknowledgementLines($this->justCauseTicked(), $handler, $ackLocale), 'model' => 'acknowledges', 'id' => 'resignation-acknowledge'])
                </div>

                <div class="flex flex-col-reverse gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ ucfirst($handler) }} is told right away and sets your last day.</p>
                    <x-ui.button wire:target="submit" wire:confirm="Send your resignation to {{ $handler }}?">Send resignation</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($this->past->isNotEmpty())
        <x-ui.card title="Earlier">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($this->past as $old)
                    <li wire:key="past-{{ $old->id }}" class="flex items-center justify-between gap-3 px-6 py-3 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">Filed {{ $old->created_at->format('M j, Y') }} · {{ $old->reasonLabel() }}</span>
                        <x-ui.badge :tone="$old->status->tone()">{{ $old->status->label() }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
