@props(['label', 'value', 'icon' => null, 'hint' => null, 'context' => null, 'tone' => 'gray', 'progress' => null, 'href' => null, 'active' => false, 'group' => null])

{{--
    One number in an <x-ui.metric-strip>: label, number, and a line under it.

    Text stays neutral; colour is only ever a small graphic, never a coloured sentence:
      - $progress (0–100): a thin bar in the $tone under the number, e.g. today's share on time;
      - otherwise a small dot in the $tone before the line (blue = needs you, amber / red = attention).
    The line reads "$hint $context": the key part a shade darker ("39%"), the rest gray ("of 31").
    A gray tone shows neither bar colour nor dot.

    With $href it's a link; with wire:click (no href) a button; else plain text.
    $active: the one chosen (e.g. the DTR filtered to "Late").

    Dividers: on a laptop the strip is one row, and each number but the first has a short line on
    its left that stops short of the card's edges. On a phone or tablet the numbers wrap onto rows,
    so each gets a full 1px line on its right and bottom instead (clipped at the card's edge).
--}}
@php
    $colors = [
        'gray' => 'bg-gray-300 dark:bg-gray-600',
        'blue' => 'bg-blue-500',
        'green' => 'bg-green-500',
        'amber' => 'bg-amber-500',
        'red' => 'bg-red-500',
    ];
    $color = $colors[$tone] ?? $colors['gray'];
    $clickable = $href !== null || $attributes->has('wire:click');
    // justify-center: when the card is stretched to its neighbour's height, the block sits in the
    // middle instead of leaving a gap below. Every number has the same lines, so rows still line up.
    $classes = 'relative flex min-w-0 flex-col justify-center px-5 py-5 text-left lg:px-6'
        // Phone / tablet: grid lines.
        .' shadow-[1px_0_0_0_var(--color-gray-200),0_1px_0_0_var(--color-gray-200)] dark:shadow-[1px_0_0_0_var(--color-gray-800),0_1px_0_0_var(--color-gray-800)] lg:shadow-none lg:dark:shadow-none'
        // Laptop: a short line on the left, except the first.
        .' lg:[&:not(:first-child)]:before:absolute lg:[&:not(:first-child)]:before:inset-y-5 lg:[&:not(:first-child)]:before:left-0 lg:[&:not(:first-child)]:before:w-px lg:[&:not(:first-child)]:before:bg-gray-200 dark:lg:[&:not(:first-child)]:before:bg-gray-800'
        .($clickable ? ' transition-colors hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none dark:hover:bg-white/[0.03] dark:focus-visible:bg-white/5' : '')
        .($active ? ' bg-gray-50 dark:bg-white/5' : '');
    $tag = $href !== null ? 'a' : ($clickable ? 'button' : 'div');
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @elseif ($tag === 'button') type="button" aria-pressed="{{ $active ? 'true' : 'false' }}" @endif {{ $attributes->class($classes) }}>
    {{-- $group: the job it belongs to ("People", "Payroll"), for someone with several. Its own small
         line, so the label beside the icon isn't cut short by a "People · " in front of it. --}}
    @if ($group)
        <span class="mb-1 block truncate text-[11px] font-medium tracking-wide text-gray-400 uppercase dark:text-gray-500">{{ $group }}</span>
    @endif
    <span class="flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400">
        {{-- A check is the round one (as on endorsements), in the icons' gray; label="": the label beside it says it. --}}
        @if ($icon === 'check')
            <x-ui.verdict-dot tone="gray" label="" />
        @elseif ($icon)
            <x-icon :name="$icon" class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" stroke-width="2" />
        @endif
        {{-- title: the whole label on hover, when a narrow tile cuts it short. --}}
        <span class="truncate" title="{{ $label }}">{{ $label }}</span>
        {{-- The filter in use: a quiet mark, not a coloured block. --}}
        @if ($active)
            <span class="ml-auto h-1.5 w-1.5 shrink-0 rounded-full {{ $color }}" aria-hidden="true"></span>
        @endif
    </span>

    {{-- A number is big; a word ("None yet") is smaller in the same height, so it doesn't wrap
         onto two lines and every number in the strip still lines up. --}}
    @php $isNumber = is_int($value) || is_float($value) || (is_string($value) && preg_match('/^[\d.,%₱+−\-—\s]*$/u', $value) === 1); @endphp
    <span @class([
        'mt-3 flex h-[1.875rem] items-end font-semibold tracking-tight text-gray-800 tabular-nums dark:text-gray-100',
        'text-3xl leading-none' => $isNumber,
        'text-lg leading-tight' => ! $isNumber,
    ])><span class="truncate">{{ $value }}</span></span>

    @if ($progress !== null)
        <span class="mt-4 block h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" aria-hidden="true">
            <span class="block h-full rounded-full {{ $color }}" style="width: {{ max(0, min(100, (int) $progress)) }}%"></span>
        </span>
    @endif

    @if (! $hint && ! $context)
        {{-- No line under it: keep the space, so this number lines up with its neighbours'. --}}
        <span @class(['block text-xs', 'mt-2' => $progress !== null, 'mt-3' => $progress === null]) aria-hidden="true">&nbsp;</span>
    @else
        <span @class(['flex min-w-0 items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400', 'mt-2' => $progress !== null, 'mt-3' => $progress === null]) title="{{ trim($hint.' '.$context) }}">
            @if ($progress === null && $tone !== 'gray')
                <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $color }}" aria-hidden="true"></span>
            @endif
            <span class="truncate">
                @if ($hint)<span class="font-medium text-gray-700 dark:text-gray-300">{{ $hint }}</span>@endif
                @if ($context){{ $context }}@endif
            </span>
        </span>
    @endif
</{{ $tag }}>
