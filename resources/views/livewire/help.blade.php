<div class="mx-auto max-w-3xl space-y-6">
    <x-ui.card title="Frequently asked questions" description="Answers to common questions about attendance, leave and pay. Still stuck? Ask HR.">
        @if ($this->canEdit)
            <x-slot:actions>
                <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="question" class="h-4 w-4" /> Add question</x-ui.button>
            </x-slot:actions>
        @endif

        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->faqs as $faq)
                <details wire:key="faq-{{ $faq->id }}" class="group px-5 py-4">
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-4 text-sm font-medium text-gray-800 dark:text-gray-100">
                        <span>
                            {{ $faq->question }}
                            @unless ($faq->is_published)<x-ui.badge tone="amber" class="ml-1">Draft</x-ui.badge>@endunless
                        </span>
                        <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-gray-400 transition-transform group-open:rotate-180" />
                    </summary>
                    {{-- e() + nl2br: HR's line breaks are kept, and nothing they type is rendered as HTML. --}}
                    <div class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">{!! nl2br(e($faq->answer)) !!}</div>
                    @if ($this->canEdit)
                        <div class="mt-3 text-xs">
                            <button type="button" wire:click="edit({{ $faq->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                            <x-ui.row-delete wire:click="delete({{ $faq->id }})" wire:confirm="Delete this question?">Delete</x-ui.row-delete>
                        </div>
                    @endif
                </details>
            @empty
                <p class="px-5 py-8 text-center text-sm text-gray-500">No questions yet.</p>
            @endforelse
        </div>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal :title="$editingId ? 'Edit question' : 'Add question'" close="cancel" max-width="lg">
            <form id="faq-form" wire:submit="save" class="space-y-4">
                <x-ui.input label="Question" wire:model="question" maxlength="255" />
                <x-ui.textarea label="Answer" wire:model="answer" rows="6" maxlength="5000" />
                <x-ui.input label="Order" type="number" min="0" wire:model="sortOrder" hint="Lower numbers come first." />
                <x-ui.checkbox id="faq-published" label="Published" description="Unpublished questions are only visible to editors." wire:model="isPublished" />
            </form>
            <x-slot:footer>
                <x-ui.button form="faq-form" wire:loading.attr="disabled" wire:target="save">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
