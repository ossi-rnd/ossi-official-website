@props(['text' => null, 'align' => 'center', 'when' => null])

{{--
    A small dark label above whatever it wraps, on hover, keyboard focus and
    tap. Mainly for explaining WHY a button is locked.

    The tooltip sits on this wrapper, not on the button: a disabled button
    fires no mouse events in most browsers, so it can't show its own tooltip.
    The wrapper is focusable (tabindex="0") so keyboard and touch users reach
    the explanation too, and aria-describedby lets screen readers read it.

    `text` may be omitted when the slot `content` is given instead, e.g. for a
    label bound with Alpine (x-text). align: center | end (for right-edge buttons) | start.
    `when`: an Alpine expression; the tooltip only appears while it is true
    (for buttons that Alpine locks and unlocks, like the camera's Capture).
--}}
@php
    $id = 'tooltip-'.\Illuminate\Support\Str::random(8);
    $position = match ($align) {
        'end' => 'right-0',
        'start' => 'left-0',
        default => 'left-1/2 -translate-x-1/2',
    };
@endphp

<span {{ $attributes->class('group/tooltip relative inline-flex') }} tabindex="0" aria-describedby="{{ $id }}">
    {{ $slot }}
    <span id="{{ $id }}" role="tooltip" @if ($when) x-show="{{ $when }}" @endif
        {{-- hidden (not invisible) until shown: an invisible label still takes room, and near the
             screen's edge it made the whole page scroll sideways on a phone. --}}
        class="pointer-events-none absolute bottom-full z-40 mb-2 hidden w-max max-w-64 rounded-md bg-gray-900 px-2.5 py-1.5 text-left text-xs leading-snug font-medium whitespace-normal text-white shadow-lg group-hover/tooltip:block group-focus-within/tooltip:block dark:bg-gray-700 {{ $position }}">
        {{ $content ?? $text }}
    </span>
</span>
