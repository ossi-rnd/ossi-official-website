@props(['count'])

{{--
    One card holding several <x-ui.metric> side by side, split by thin lines.

    Columns: 2 on a phone, up to 3 on a tablet, and on a laptop all of them in one row, up to
    6. More than 6 (a Super Admin sees every job's numbers) wrap into even rows instead of
    squeezing: 4 a row on a laptop (11 → 4 + 4 + 3), 6 on a wide screen (11 → 6 + 5).

    The lines are each cell's 1px shadow on its right and bottom; the grid is pulled 1px past the
    card's edge so the outer ones are clipped. Unlike a gap over a gray background, an unfilled
    last row stays white instead of showing a gray block. In one row on a laptop, each number
    gets a short line on its left instead (x-ui.metric); wrapped rows keep the grid lines.
--}}
@php
    $sm = $count % 3 === 0 || $count >= 5 ? min($count, 3) : min($count, 2);
    // Even rows of at most $per: 11 → 6 + 5 (or 4 + 4 + 3).
    $even = fn (int $per): int => (int) ceil($count / max(1, (int) ceil($count / $per)));
    $wraps = $count > 6;
    // Wrapping anyway: 4 a row on a laptop, 6 on a wide screen, so labels have room.
    $lg = $wraps ? $even(4) : $count;
    $xl = $wraps ? $even(6) : $count;
@endphp
<div {{ $attributes->class('ui-card overflow-hidden') }}>
    {{-- On a phone (2 columns) an odd last one spans the row instead of leaving an empty half. --}}
    <div @class([
            '-mr-px -mb-px grid grid-cols-2 sm:grid-cols-[repeat(var(--metric-sm),minmax(0,1fr))] lg:grid-cols-[repeat(var(--metric-lg),minmax(0,1fr))] 2xl:grid-cols-[repeat(var(--metric-xl),minmax(0,1fr))] [&>*:last-child:nth-child(odd)]:col-span-2 sm:[&>*:last-child:nth-child(odd)]:col-span-1',
            // Wrapped rows on a laptop: keep the phone's grid lines, not the one-row short dividers.
            'lg:[&>*]:shadow-[1px_0_0_0_var(--color-gray-200),0_1px_0_0_var(--color-gray-200)]! dark:lg:[&>*]:shadow-[1px_0_0_0_var(--color-gray-800),0_1px_0_0_var(--color-gray-800)]! lg:[&>*]:before:hidden!' => $wraps,
        ])
        style="--metric-sm: {{ $sm }}; --metric-lg: {{ $lg }}; --metric-xl: {{ $xl }}">
        {{ $slot }}
    </div>
</div>
