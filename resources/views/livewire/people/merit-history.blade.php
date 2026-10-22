@php
    $signed = fn (int $points): string => $points > 0 ? '+'.$points : '−'.abs($points);
    $totals = $this->totals;
    $own = $this->isOwn();
@endphp

<div>
    <x-ui.card title="Merits & demerits" :description="$own ? 'Recognition and minor offences recorded by your supervisors. You can add your side to a demerit within '.\App\Models\MeritRecord::REPLY_DAYS.' days.' : 'The last 12 months count toward the total.'">
        <x-slot:actions>
            @if (! $own && $this->canGive())
                <a href="{{ route('merits', ['give' => $userId]) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Give one</a>
            @endif
        </x-slot:actions>

        <x-ui.metric-strip :count="3">
            <x-ui.metric icon="check" label="Merit points" :value="$totals['merits']" hint="last 12 months" />
            <x-ui.metric icon="warning" label="Demerit points" :value="$totals['demerits']" hint="last 12 months" />
            <x-ui.metric icon="scroll" label="Net" :value="$totals['net'] === 0 ? '0' : $signed($totals['net'])" hint="merits − demerits" />
        </x-ui.metric-strip>

        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->records as $record)
                <li wire:key="merit-{{ $record->id }}" @class(['px-5 py-4', 'opacity-60' => $record->voided_at])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-800 dark:text-gray-100">
                                <span @class(['font-medium', 'line-through' => $record->voided_at])>{{ $record->title }}</span>
                                <span class="text-xs text-gray-500"> · {{ $record->isDemerit() ? 'Demerit' : 'Merit' }} · {{ $record->occurred_on->format('M j, Y') }}</span>
                            </p>
                            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $record->reason }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">Given by {{ $record->giver?->name ?? '—' }}</p>

                            @if ($record->voided_at)
                                <p class="mt-1 text-xs text-gray-500">Taken back by {{ $record->voider?->name ?? '—' }}: {{ $record->void_reason }}</p>
                            @endif
                            @if ($record->employee_reply)
                                <div class="mt-2 rounded-md bg-gray-50 p-2.5 text-sm dark:bg-white/[0.03]">
                                    <p class="text-xs font-medium text-gray-500">{{ $own ? 'Your side' : 'Their side' }} · {{ $record->replied_at?->format('M j') }}</p>
                                    <p class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $record->employee_reply }}</p>
                                </div>
                            @elseif ($record->awaitsReply())
                                <p class="mt-1 flex items-center gap-1.5 text-xs text-gray-500"><span class="size-1.5 rounded-full bg-amber-500"></span>{{ $own ? 'You can' : 'They can' }} add {{ $own ? 'your' : 'their' }} side until {{ $record->reply_until?->format('M j') }}</p>
                            @endif
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-sm font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $signed($record->points) }}</p>
                            @if ($own && $record->awaitsReply() && $replyingId !== $record->id)
                                <button type="button" wire:click="startReply({{ $record->id }})" class="mt-1 text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Add my side</button>
                            @elseif (! $own && ! $record->voided_at && $this->canGive() && $voidingId !== $record->id)
                                <button type="button" wire:click="startVoid({{ $record->id }})" class="mt-1 text-xs font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Take back</button>
                            @endif
                        </div>
                    </div>

                    @if ($replyingId === $record->id)
                        <form wire:submit="sendReply" class="mt-3 space-y-2">
                            <x-ui.textarea label="Your side" wire:model="reply" rows="3" maxlength="1000" placeholder="e.g. My shoes were being repaired; my supervisor knew and allowed it." />
                            <div class="flex justify-end gap-2">
                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="$set('replyingId', null)">Cancel</x-ui.button>
                                <x-ui.button size="sm" wire:target="sendReply">Send</x-ui.button>
                            </div>
                        </form>
                    @endif
                    @if ($voidingId === $record->id)
                        <form wire:submit="void" class="mt-3 space-y-2">
                            <x-ui.input label="Why take it back?" wire:model="voidReason" placeholder="e.g. Given to the wrong guard" />
                            <div class="flex justify-end gap-2">
                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="$set('voidingId', null)">Cancel</x-ui.button>
                                <x-ui.button size="sm" variant="danger-outline" wire:target="void">Take back</x-ui.button>
                            </div>
                        </form>
                    @endif
                </li>
            @empty
                <li class="px-5 py-8 text-center text-sm text-gray-500">No merits or demerits yet.</li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
