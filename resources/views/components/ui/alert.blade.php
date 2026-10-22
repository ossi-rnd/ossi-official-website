@props(['tone' => 'blue'])

@php
    $tones = [
        'blue' => ['bg-blue-50 text-blue-800 border-blue-200 dark:bg-blue-500/10 dark:text-blue-200 dark:border-blue-500/20', 'info'],
        'green' => ['bg-green-50 text-green-800 border-green-200 dark:bg-green-500/10 dark:text-green-200 dark:border-green-500/20', 'check'],
        'amber' => ['bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:border-amber-500/20', 'warning'],
        'red' => ['bg-red-50 text-red-800 border-red-200 dark:bg-red-500/10 dark:text-red-200 dark:border-red-500/20', 'warning'],
    ];
    [$classes, $icon] = $tones[$tone] ?? $tones['blue'];
@endphp

<div role="{{ $tone === 'red' ? 'alert' : 'status' }}" {{ $attributes->class('flex items-start gap-2.5 rounded-md border px-4 py-3 text-sm '.$classes) }}>
    <x-icon :name="$icon" class="mt-0.5 h-4 w-4 shrink-0" />
    <div class="min-w-0">{{ $slot }}</div>
</div>
