@props(['yes' => true, 'label' => null, 'tone' => 'blue', 'size' => 'sm'])

{{--
    A small solid dot with a white check (yes) or cross (no): an endorsement, a decision.
    Drawn for 16px on its own 16-unit grid; shrinking the 24px outline icons makes them thin and loose.
        <x-ui.verdict-dot :yes="$endorsement->recommends" />
    tone: the colour of a "yes": blue for a decision; gray as an icon among gray icons (the dashboard's "On time").
    size: sm (16px, in a line of text) or md (20px, e.g. an empty state's "all caught up").
--}}
@php
    [$dot, $mark] = $size === 'md' ? ['h-5 w-5', 'h-3.5 w-3.5'] : ['h-4 w-4', 'h-3 w-3'];
    $yesColor = match ($tone) {
        'green' => 'bg-green-600 dark:bg-green-500',
        'gray' => 'bg-gray-400 dark:bg-gray-500 dark:text-gray-800',
        default => 'bg-blue-600 dark:bg-blue-500',
    };
@endphp
<span {{ $attributes->class(['inline-flex shrink-0 items-center justify-center rounded-full text-white', $dot, $yesColor => $yes, 'bg-red-600 dark:bg-red-500' => ! $yes]) }}>
    <svg viewBox="0 0 16 16" class="{{ $mark }}" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        @if ($yes)
            <path d="M4.5 8.5 7 11l4.5-5.5" />
        @else
            <path d="M5.5 5.5l5 5m0-5-5 5" />
        @endif
    </svg>
    <span class="sr-only">{{ $label ?? ($yes ? 'Endorsed' : 'Not recommended') }}</span>
</span>
