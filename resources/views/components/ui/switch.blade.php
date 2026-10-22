@props(['label', 'description' => null])

{{-- An on/off setting: a real checkbox (role="switch", so wire:model, the keyboard and
     screen readers all work) drawn as a sliding track. Text on the left, switch on the right. --}}
@php $id = $attributes->get('id') ?? str_replace('.', '-', (string) ($attributes->wire('model')->value() ?: $attributes->get('name'))); @endphp

<label for="{{ $id }}" class="group flex cursor-pointer items-center justify-between gap-4 rounded-lg border border-gray-200 px-3 py-2.5 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/50">
    <span class="min-w-0">
        <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ $label }}</span>
        @if ($description)
            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $description }}</span>
        @endif
    </span>
    <span class="relative inline-flex shrink-0">
        <input id="{{ $id }}" type="checkbox" role="switch" {{ $attributes->except('id')->class('peer sr-only') }}>
        <span aria-hidden="true" class="h-6 w-11 rounded-full bg-gray-300 transition-colors group-hover:bg-gray-400 peer-checked:bg-blue-600 peer-checked:group-hover:bg-blue-700 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 peer-focus-visible:ring-offset-2 dark:bg-gray-600 dark:group-hover:bg-gray-500 dark:peer-checked:bg-blue-600 dark:peer-checked:group-hover:bg-blue-500 dark:peer-focus-visible:ring-offset-gray-900"></span>
        <span aria-hidden="true" class="pointer-events-none absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
    </span>
</label>
