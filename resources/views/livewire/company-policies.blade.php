<div class="mx-auto max-w-3xl space-y-6">
    <x-ui.card title="Company Policy" description="The rules everyone at the company follows. Questions? Ask HR.">
        @can(\App\Enums\Ability::ManagePolicies->value)
            <x-slot:actions>
                <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="plus" class="h-4 w-4" /> Add section</x-ui.button>
            </x-slot:actions>
        @endcan

        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->policies as $policy)
                <details wire:key="policy-{{ $policy->id }}" class="group p-4" @if ($loop->first) open @endif>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
                        <span class="font-medium text-gray-800 dark:text-gray-100">{{ $policy->title }}</span>
                        <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-gray-400 transition-transform group-open:rotate-180" />
                    </summary>
                    <div class="mt-3 space-y-3 text-sm leading-relaxed whitespace-pre-line text-gray-700 dark:text-gray-200">{{ $policy->body }}</div>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                        <span>Updated {{ $policy->updated_at->format('M j, Y') }}{{ $policy->editor ? ' by '.$policy->editor->name : '' }}</span>
                        <span class="flex items-center gap-3">
                            @if ($policy->file_path)
                                <a href="{{ route('company-policy.file', $policy) }}" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Signed copy (PDF)</a>
                            @endif
                            @can(\App\Enums\Ability::ManagePolicies->value)
                                <button type="button" wire:click="edit({{ $policy->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                <x-ui.row-delete wire:click="delete({{ $policy->id }})" wire:confirm="Delete &quot;{{ $policy->title }}&quot;?">Delete</x-ui.row-delete>
                            @endcan
                        </span>
                    </div>
                </details>
            @empty
                <p class="p-8 text-center text-sm text-gray-500">No policies posted yet.</p>
            @endforelse
        </div>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal :title="$editingId ? 'Edit policy' : 'Add policy section'" close="cancel" max-width="2xl">
            <form id="policy-form" wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div class="sm:col-span-3"><x-ui.input label="Title" wire:model="title" placeholder="e.g. Attendance and punctuality" /></div>
                    <x-ui.input label="Order" type="number" min="0" wire:model="sortOrder" />
                </div>
                <x-ui.textarea label="Policy text" wire:model="body" rows="10" maxlength="20000" />
                @if ($editing?->file_path && ! $removeFile)
                    <p class="text-sm text-gray-600 dark:text-gray-300">Signed copy: {{ $editing->file_name }} · <x-ui.row-delete wire:click="$set('removeFile', true)">Remove</x-ui.row-delete></p>
                @endif
                <x-ui.file-input :label="$editing?->file_path ? 'Replace the signed copy (optional)' : 'Signed copy (optional)'" model="file" id="policy-file" accept="application/pdf" hint="PDF only." />
            </form>
            <x-slot:footer>
                <x-ui.button form="policy-form" wire:loading.attr="disabled" wire:target="file, save">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
