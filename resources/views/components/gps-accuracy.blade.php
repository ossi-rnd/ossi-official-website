@props(['meters' => null, 'plain' => false, 'dot' => false])

{{--
    How sure the phone is about a location, in words: "Precise location",
    "Rough location, within ~95 m"… The exact metres are in the tooltip.
    `plain`: inline text instead of a badge (for tight lines).
    `dot`: a small coloured dot (for lists like the timeline); the words show
    only for a weak signal, the one case worth reading.
--}}
@php $accuracy = \App\Support\GpsAccuracy::describe($meters === null ? null : (int) $meters); @endphp

@if ($accuracy)
    <x-ui.tooltip :text="$accuracy['label'].'. '.$accuracy['detail']" {{ $attributes }}>
        @if ($dot)
            <span class="inline-flex cursor-help items-center gap-1">
                <span @class(['h-2 w-2 rounded-full', 'bg-blue-600' => $accuracy['tone'] === 'blue', 'bg-gray-400' => $accuracy['tone'] === 'gray', 'bg-amber-500' => $accuracy['tone'] === 'amber', 'bg-red-600' => $accuracy['tone'] === 'red'])></span>
                @if ($accuracy['tone'] === 'red')
                    <span class="text-red-600 dark:text-red-400">{{ $accuracy['label'] }}</span>
                @else
                    <span class="sr-only">{{ $accuracy['label'] }}</span>
                @endif
            </span>
        @elseif ($plain)
            <span @class(['cursor-help underline decoration-dotted underline-offset-2', 'text-red-600 dark:text-red-400' => $accuracy['tone'] === 'red', 'text-amber-700 dark:text-amber-400' => $accuracy['tone'] === 'amber'])>{{ $accuracy['label'] }}</span>
        @else
            <x-ui.badge :tone="$accuracy['tone']" class="cursor-help">{{ $accuracy['label'] }}</x-ui.badge>
        @endif
    </x-ui.tooltip>
@endif
