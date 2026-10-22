@props(['label', 'value', 'tone' => 'gray', 'hint' => null])

@php
    $tones = [
        'gray' => 'text-gray-800 dark:text-gray-100',
        'green' => 'text-green-600 dark:text-green-400',
        'amber' => 'text-amber-600 dark:text-amber-400',
        'red' => 'text-red-600 dark:text-red-400',
        'blue' => 'text-blue-600 dark:text-blue-400',
        'muted' => 'text-gray-600 dark:text-gray-300',
    ];
@endphp

<div {{ $attributes->class('ui-card p-4') }}>
    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums {{ $tones[$tone] ?? $tones['gray'] }}">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
</div>
