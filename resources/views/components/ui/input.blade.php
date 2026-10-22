@props(['label' => null, 'name' => null, 'type' => 'text', 'hint' => null, 'bag' => 'default'])

@php
    // Works for both kinds of form: wire:model="form.email" (Livewire) or name="email" (Fortify).
    // `bag` reads Fortify's named error bags, e.g. bag="updatePassword".
    $key = $attributes->wire('model')->value() ?: $name;
    $id = $attributes->get('id') ?? str_replace('.', '-', ($bag !== 'default' ? $bag.'-' : '').$key);
    $messages = $errors->getBag($bag);
    $hasError = $key !== null && $messages->has($key);
@endphp

<div>
    @if ($label)
        <label for="{{ $id }}" class="ui-label">{{ $label }}</label>
    @endif

    @if ($type === 'password')
        {{-- Show / hide: the eye button switches the field between dots and plain text. It starts
             hidden, and the server always receives the same value either way. --}}
        <div class="relative" x-data="{ shown: false }">
            <input id="{{ $id }}" type="password" x-bind:type="shown ? 'text' : 'password'" @if ($name) name="{{ $name }}" @endif
                @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif ($hint) aria-describedby="{{ $id }}-hint" @endif
                {{ $attributes->except('id')->class(['ui-field pr-11', 'border-red-500! focus:ring-red-500!' => $hasError]) }}>
            <button type="button" x-on:click="shown = ! shown" aria-controls="{{ $id }}"
                x-bind:aria-pressed="shown.toString()" x-bind:aria-label="shown ? 'Hide password' : 'Show password'" aria-label="Show password"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-md text-gray-400 hover:text-gray-600 focus-visible:text-gray-600 focus-visible:outline-none dark:text-gray-500 dark:hover:text-gray-300 dark:focus-visible:text-gray-300">
                <x-icon name="eye" class="h-5 w-5" x-show="! shown" />
                <x-icon name="eye-slash" class="h-5 w-5" x-show="shown" x-cloak />
            </button>
        </div>
    @else
        <input id="{{ $id }}" type="{{ $type }}" @if ($name) name="{{ $name }}" @endif
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif ($hint) aria-describedby="{{ $id }}-hint" @endif
            {{ $attributes->except('id')->class(['ui-field', 'border-red-500! focus:ring-red-500!' => $hasError]) }}>
    @endif

    @if ($hasError)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $messages->first($key) }}</p>
    @elseif ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
</div>
