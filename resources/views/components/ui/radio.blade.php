@props(['label', 'description' => null])

{{-- One choice of several (same name / wire:model), laid out like x-ui.checkbox. --}}
@php $id = $attributes->get('id') ?? str_replace('.', '-', (string) ($attributes->wire('model')->value() ?: $attributes->get('name'))).'-'.$attributes->get('value'); @endphp

<label for="{{ $id }}" class="flex cursor-pointer items-start gap-3">
    <input id="{{ $id }}" type="radio" {{ $attributes->except('id')->class('mt-0.5 h-4 w-4 shrink-0 border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-blue-500') }}>
    <span>
        <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ $label }}</span>
        @if ($description)
            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $description }}</span>
        @endif
    </span>
</label>
