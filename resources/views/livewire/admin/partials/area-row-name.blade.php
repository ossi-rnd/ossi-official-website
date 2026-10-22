{{--
    One "People by area" row's name, on one line: the open/close arrow (regions and provinces),
    the name, what's inside it ($hint: "5 provinces · 8 clients"), and a link to the Employees
    list filtered to that area (shown on hover; always on touch screens, which have no hover).
    $level: 0 region, 1 province. $plain: no arrow (Office staff…). The row is `group/row`.
--}}
@php
    $plain ??= false;
    $hint ??= null;
    $arrow = $level === 0 ? 'group-open/region:rotate-90' : 'group-open/province:rotate-90';
@endphp
<span class="flex min-w-0 items-center gap-2">
    {{-- The same icon, hidden, on rows that don't open: names line up either way. --}}
    <x-icon name="chevron-right" :class="\Illuminate\Support\Arr::toCssClasses(['h-4 w-4 shrink-0 text-gray-400 transition-transform', $arrow => ! $plain, 'invisible' => $plain])" />
    <span @class(['truncate', 'font-medium text-gray-800 dark:text-gray-100' => $level === 0, 'text-gray-700 dark:text-gray-200' => $level > 0])>{{ $name }}</span>
    @if ($hint)
        <span class="hidden shrink-0 text-xs text-gray-400 sm:inline">{{ $hint }}</span>
    @endif
    {{-- Clicking it doesn't open or close the row. No $href: not a list this person may open. --}}
    @if ($href)
        <a href="{{ $href }}" wire:navigate x-on:click.stop
            class="shrink-0 rounded p-0.5 text-gray-400 transition hover:bg-gray-100 hover:text-blue-600 focus-visible:opacity-100 sm:opacity-0 sm:group-hover/row:opacity-100 dark:hover:bg-white/5 dark:hover:text-blue-400"
            aria-label="Show {{ $name }} on Employees" title="Show on Employees">
            <x-icon name="users" class="h-3.5 w-3.5" />
        </a>
    @endif
</span>
