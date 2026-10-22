{{--
    One box of the organization chart: the title, who holds it (up to three names
    linking to their records, the rest as "+N more"), or "Not assigned".
    $box: ['title' => string, 'people' => Collection<User>]; $tone: hexagon | dark | pill | blue | plain.
    hexagon (the Board of Directors, at the top): wider, with room inside the slanted sides.
--}}
@php
    $people = $box['people'];
    $tone ??= 'plain';
    $shown = $people->take(3);
@endphp
@if ($tone === 'hexagon')<div class="org-hexagon">@endif
<div @class([
    'text-center',
    'w-40 rounded-xl border px-3 py-2.5 shadow-sm' => $tone !== 'hexagon',
    'w-56 bg-white px-10 py-4 dark:bg-gray-800' => $tone === 'hexagon',
    'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-800' => $tone === 'dark',
    'rounded-full! border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800' => $tone === 'pill',
    'border-blue-200 bg-blue-50 dark:border-blue-500/30 dark:bg-blue-500/10' => $tone === 'blue',
    'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800' => $tone === 'plain',
])>
    <p @class(['text-xs font-semibold tracking-wide uppercase', 'text-white dark:text-gray-800' => $tone === 'dark', 'text-blue-700 dark:text-blue-300' => $tone === 'blue', 'text-gray-500 dark:text-gray-400' => in_array($tone, ['pill', 'plain'], true), 'text-gray-800 dark:text-gray-100' => $tone === 'hexagon'])>{{ $box['title'] }}</p>
    @if ($people->isEmpty())
        <p @class(['mt-1 text-xs italic', 'text-gray-300 dark:text-gray-600' => $tone === 'dark', 'text-gray-400' => $tone !== 'dark'])>Not assigned</p>
    @else
        <ul class="mt-1 space-y-0.5 text-xs leading-snug">
            @foreach ($shown as $person)
                <li class="truncate">
                    @if ($tone === 'dark')
                        <span class="font-medium">{{ $person->name }}</span>
                    @else
                        <x-person-link :user="$person" />
                    @endif
                </li>
            @endforeach
            @if ($people->count() > 3)
                <li @class(['text-gray-300 dark:text-gray-600' => $tone === 'dark', 'text-gray-500' => $tone !== 'dark']) title="{{ $people->slice(3)->pluck('name')->implode(', ') }}">+{{ $people->count() - 3 }} more</li>
            @endif
        </ul>
    @endif
</div>
@if ($tone === 'hexagon')</div>@endif
