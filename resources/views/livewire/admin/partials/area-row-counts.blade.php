{{--
    One "People by area" row's numbers. Zeros are faint, so the ones that matter stand out.
    The last column is the share in today (in ÷ people not on leave) as a small bar: easy to
    compare regions at a glance; "—" where nobody is expected.
--}}
@php
    $cell = fn (int $value, string $tone = '') => $value === 0 ? 'text-gray-300 dark:text-gray-600' : $tone;
    $expected = $counts['people'] - $counts['onLeave'];
    $share = $expected > 0 ? (int) round($counts['in'] / $expected * 100) : null;
@endphp
<span @class(['text-right tabular-nums', 'font-semibold text-gray-800 dark:text-gray-100' => $strong])>{{ $counts['people'] }}</span>
@if ($showAttendance)
    <span class="text-right tabular-nums {{ $cell($counts['in'], 'text-gray-700 dark:text-gray-200') }}">{{ $counts['in'] }}</span>
    <span class="hidden text-right tabular-nums sm:block {{ $cell($counts['late'], 'text-amber-600 dark:text-amber-400') }}">{{ $counts['late'] }}</span>
    <span class="hidden text-right tabular-nums sm:block {{ $cell($counts['notIn'], 'text-red-600 dark:text-red-400') }}">{{ $counts['notIn'] }}</span>
    <span class="hidden text-right tabular-nums sm:block {{ $cell($counts['onLeave'], 'text-gray-600 dark:text-gray-300') }}">{{ $counts['onLeave'] }}</span>
    <span class="hidden items-center justify-end gap-2 sm:flex" @if ($share !== null) title="{{ $counts['in'] }} of {{ $expected }} expected are in" @endif>
        @if ($share === null)
            <span class="text-gray-300 dark:text-gray-600">—</span>
        @else
            <span class="h-1.5 w-12 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" aria-hidden="true"><span class="block h-full rounded-full bg-blue-500" style="width: {{ $share }}%"></span></span>
            <span class="w-9 text-right text-xs tabular-nums {{ $share === 0 ? 'text-gray-400' : 'text-gray-700 dark:text-gray-200' }}">{{ $share }}%</span>
        @endif
    </span>
@endif
