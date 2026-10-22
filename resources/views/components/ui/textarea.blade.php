@props(['label' => null, 'name' => null, 'rows' => 3, 'hint' => null])

@php
    $key = $attributes->wire('model')->value() ?: $name;
    $id = $attributes->get('id') ?? str_replace('.', '-', (string) $key);
    $hasError = $key !== null && $errors->has($key);
@endphp

{{-- Resizes in height only: dragging it wider would break the form's grid. --}}
<div>
    @if ($label)
        <label for="{{ $id }}" class="ui-label">{{ $label }}</label>
    @endif

    <textarea id="{{ $id }}" rows="{{ $rows }}" @if ($name) name="{{ $name }}" @endif
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif ($hint) aria-describedby="{{ $id }}-hint" @endif
        {{ $attributes->class(['ui-field resize-y', 'border-red-500!' => $hasError]) }}>{{ $slot }}</textarea>

    @if ($hasError)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $errors->first($key) }}</p>
    @elseif ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
</div>
