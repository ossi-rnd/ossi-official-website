@props(['label', 'description' => null])

@php $id = $attributes->get('id') ?? str_replace('.', '-', (string) ($attributes->wire('model')->value() ?: $attributes->get('name'))); @endphp

<label for="{{ $id }}" class="flex items-start gap-3">
    <input id="{{ $id }}" type="checkbox" {{ $attributes->except('id')->class('mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800') }}>
    <span>
        <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ $label }}</span>
        @if ($description)
            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $description }}</span>
        @endif
    </span>
</label>
