<div class="mx-auto max-w-3xl space-y-6">
    <div class="ui-card p-5 text-sm text-gray-600 dark:text-gray-300">
        <p class="font-medium text-gray-800 dark:text-gray-100">About Notices to Explain</p>
        <p class="mt-1">A notice asks for your side before any decision is made. Read it, mark it received (that's not agreeing), and explain in writing by the date given. You can ask for a hearing. You'll be told the decision and its reasons here and by email.</p>
    </div>

    @forelse ($this->notices as $notice)
        <x-ui.card wire:key="my-nte-{{ $notice->id }}" :title="$notice->subject" :description="$notice->isDecided() ? 'Decided '.$notice->decided_at?->format('M j, Y') : 'Explain by '.$notice->explain_by->format('M j, Y')">
            <x-slot:actions>
                @if ($notice->isDecided())
                    <x-ui.badge :tone="$notice->decision?->tone() ?? 'gray'">{{ $notice->decision?->label() }}</x-ui.badge>
                @else
                    <x-ui.badge tone="amber">{{ $notice->canStillExplain() ? 'Waiting for your explanation' : 'Being decided' }}</x-ui.badge>
                @endif
            </x-slot:actions>

            <div class="p-5">
                @include('livewire.partials.nte-document', ['notice' => $notice, 'own' => true])

                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    @if (! $notice->isDecided() && ! $notice->acknowledged_at)
                        <x-ui.button type="button" variant="secondary" wire:click="acknowledge({{ $notice->id }})">I received this notice</x-ui.button>
                    @endif
                    @if ($notice->isDecided() && ! $notice->decision_acknowledged_at)
                        <x-ui.button type="button" variant="secondary" wire:click="acknowledge({{ $notice->id }})">I received the decision</x-ui.button>
                    @endif
                    @if ($notice->canStillExplain() && $explainingId !== $notice->id)
                        <x-ui.button type="button" wire:click="startExplaining({{ $notice->id }})">Write my explanation</x-ui.button>
                    @endif
                </div>

                @if ($explainingId === $notice->id)
                    <form wire:submit="explain" class="mt-4 space-y-3 rounded-lg border border-dashed border-gray-300 p-4 dark:border-gray-700">
                        <x-ui.textarea label="My explanation" wire:model="explanation" rows="5" maxlength="5000" placeholder="What happened, in your words: the time, the place, why, and anyone who can confirm it." />
                        <x-ui.checkbox id="wants-hearing-{{ $notice->id }}" wire:model="wantsHearing" label="I'd like a hearing to explain in person" />
                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="ghost" wire:click="$set('explainingId', null)">Cancel</x-ui.button>
                            <x-ui.button wire:target="explain" wire:confirm="Send your explanation? It can't be changed after.">Send my explanation</x-ui.button>
                        </div>
                    </form>
                @endif
            </div>
        </x-ui.card>
    @empty
        <div class="ui-card p-8 text-center text-sm text-gray-500">You have no notices.</div>
    @endforelse
</div>
