<x-ui.card title="Profile photo" description="Shown in the top bar and to HR. JPG, PNG or WebP, up to 2 MB.">
    <div class="flex flex-col items-center gap-5 p-6 sm:flex-row"
        x-data="{ uploading: false, progress: 0 }"
        x-on:livewire-upload-start="uploading = true; progress = 0"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-error="uploading = false"
        x-on:livewire-upload-cancel="uploading = false">
        @if ($avatar && method_exists($avatar, 'isPreviewable') && $avatar->isPreviewable())
            <img src="{{ $avatar->temporaryUrl() }}" alt="" class="h-20 w-20 rounded-full object-cover">
        @else
            <x-ui.avatar :user="$user" size="lg" />
        @endif

        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
            <label x-bind:class="uploading && 'pointer-events-none opacity-60'" class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                <x-icon name="camera" class="h-4 w-4" /> Choose photo
                <input type="file" wire:model="avatar" accept="image/jpeg,image/png,image/webp" class="sr-only">
            </label>

            @if ($avatar)
                <x-ui.button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save,avatar">Save photo</x-ui.button>
                <x-ui.button type="button" variant="ghost" wire:click="$set('avatar', null)">Cancel</x-ui.button>
            @elseif ($user->avatar_path)
                <x-ui.button type="button" variant="ghost" class="text-red-600! dark:text-red-400!" wire:click="remove" wire:confirm="Remove your profile photo?">Remove photo</x-ui.button>
            @endif

            <div x-show="uploading" x-cloak class="flex min-w-40 flex-1 items-center gap-2" role="progressbar" aria-label="Upload progress" x-bind:aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    <div class="h-full rounded-full bg-blue-600 transition-[width] duration-200" x-bind:style="`width: ${progress}%`"></div>
                </div>
                <span class="text-xs text-gray-500 tabular-nums" x-text="progress + '%'"></span>
            </div>
        </div>
    </div>

    @error('avatar')
        <p class="px-6 pb-4 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</x-ui.card>
