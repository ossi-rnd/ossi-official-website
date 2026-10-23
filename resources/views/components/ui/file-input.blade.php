@props(['label' => null, 'model', 'accept' => null, 'hint' => null, 'id' => null])

@php
    $id ??= 'file-'.str_replace('.', '-', $model);
    $hasError = $errors->has($model);
@endphp

{{--
    A file field that shows what's happening. Livewire uploads the file the
    moment it's chosen and fires livewire-upload-* events on the input (they
    bubble here), so Alpine shows the name, a live progress strip with %, a
    way to cancel, and "Uploaded" once the server has it. The form's Save
    button stays disabled meanwhile (wire:loading.attr + wire:target on it).

    The box never changes height: one name line, one status line (truncated),
    and the progress strip drawn inside its bottom edge. The hint sits below
    it and never changes either, so a modal around it doesn't jump (or
    cross-fade between two sizes) when an upload starts or finishes.
--}}
<div x-data="{ name: '', uploading: false, progress: 0, done: false, failed: false }"
    x-on:livewire-upload-start="uploading = true; progress = 0; done = false; failed = false"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
    x-on:livewire-upload-finish="uploading = false; done = true"
    x-on:livewire-upload-error="uploading = false; failed = true"
    x-on:livewire-upload-cancel="uploading = false; progress = 0; name = ''">
    @if ($label)
        <label for="{{ $id }}" class="ui-label">{{ $label }}</label>
    @endif

    <div @class([
        'relative mt-1 flex h-16 items-center gap-3 overflow-hidden rounded-lg border border-dashed px-3 transition-colors focus-within:ring-2 focus-within:ring-blue-500',
        'border-red-400 bg-red-50/40 dark:border-red-500/50 dark:bg-red-500/5' => $hasError,
        'border-gray-300 bg-white hover:border-blue-400 hover:bg-blue-50/40 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-blue-500/60 dark:hover:bg-blue-500/10' => ! $hasError,
    ])>
        {{-- The whole box opens the file picker... --}}
        <label for="{{ $id }}" class="absolute inset-0 cursor-pointer" x-bind:class="uploading && 'pointer-events-none'">
            <span class="sr-only">{{ $label ?? 'Choose a file' }}</span>
        </label>

        <span class="pointer-events-none flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
            <x-icon name="upload" class="h-4 w-4" x-show="!done" />
            <x-icon name="check" class="h-4 w-4 text-blue-600 dark:text-blue-400" x-show="done" x-cloak />
        </span>

        <span class="pointer-events-none min-w-0 flex-1">
            <span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-100" x-text="name || 'Choose a file'"></span>
            <span class="block truncate text-xs">
                <span class="text-gray-500 dark:text-gray-400" x-show="!uploading && !done && !failed">Click to browse</span>
                <span class="text-blue-600 dark:text-blue-400" x-show="uploading" x-cloak x-text="'Uploading… ' + progress + '%'"></span>
                @unless ($hasError)
                    <span class="text-blue-600 dark:text-blue-400" x-show="done" x-cloak>Uploaded. Press the button below to save it.</span>
                @endunless
                <span class="text-red-600 dark:text-red-400" x-show="failed" x-cloak>Didn't go through. Choose the file again.</span>
            </span>
        </span>

        {{-- ...except Cancel, which sits above the click area while uploading. --}}
        <span class="pointer-events-none shrink-0 text-sm font-medium text-blue-600 dark:text-blue-400" x-show="!uploading" x-text="name ? 'Change' : 'Browse'"></span>
        <button type="button" x-show="uploading" x-cloak class="relative z-10 shrink-0 text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300" x-on:click="$wire.cancelUpload('{{ $model }}')">Cancel</button>

        <div x-show="uploading" x-cloak class="pointer-events-none absolute inset-x-0 bottom-0 h-1 bg-gray-200 dark:bg-gray-700" role="progressbar" aria-label="Upload progress" x-bind:aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full bg-blue-600 transition-[width] duration-200" x-bind:style="`width: ${progress}%`"></div>
        </div>

        <input id="{{ $id }}" type="file" wire:model="{{ $model }}" @if ($accept) accept="{{ $accept }}" @endif class="sr-only"
            x-on:change="name = $event.target.files[0]?.name ?? ''"
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif ($hint) aria-describedby="{{ $id }}-hint" @endif>
    </div>

    @error($model)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @else
        @if ($hint)
            <p id="{{ $id }}-hint" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
        @endif
    @enderror
</div>
