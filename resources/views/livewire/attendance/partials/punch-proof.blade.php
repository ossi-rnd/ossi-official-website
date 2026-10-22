{{-- One clock-in or clock-out of a shift on the Punch Log: selfie, time, place, map link, and
     lateness / leaving early in words. Needs $log, $punch (or null), $at, $what. --}}
<div class="flex gap-3">
    @if ($punch)
        <button type="button" x-on:click="open = true; photo = @js($punch->photoUrl()); caption = @js($what.' · '.$punch->punched_at->format('M j, Y g:i A'))"
            class="block h-14 w-10 shrink-0 overflow-hidden rounded-md border border-gray-200 bg-gray-100 hover:ring-2 hover:ring-blue-400 dark:border-gray-700 dark:bg-gray-800 dark:hover:ring-blue-500" aria-label="View your {{ strtolower($what) }} selfie">
            <img src="{{ $punch->photoUrl() }}" alt="" class="h-full w-full object-cover" loading="lazy">
        </button>
    @endif
    <div class="min-w-0 text-sm">
        <p class="text-xs font-medium tracking-wide text-gray-400 uppercase">{{ $what }}</p>
        @if ($at)
            <p class="font-medium text-gray-800 tabular-nums dark:text-gray-100">{{ $at->format('g:i A') }}@unless ($at->isSameDay($log->log_date))<span class="font-normal text-gray-500"> · {{ $at->format('M j') }}</span>@endunless</p>
            @if ($what === 'Clock in' && $log->late_minutes > 0)
                <p class="text-xs text-amber-700 dark:text-amber-400">{{ \App\Support\Duration::minutes($log->late_minutes) }} late</p>
            @elseif ($what === 'Clock out' && $log->undertime_minutes > 0)
                <p class="text-xs text-amber-700 dark:text-amber-400">Left {{ \App\Support\Duration::minutes($log->undertime_minutes) }} early</p>
            @endif
            @if ($punch)
                <p class="mt-0.5 text-xs break-words text-gray-500 dark:text-gray-400">{{ $punch->address ?? 'Address unavailable' }}</p>
                <a href="{{ $punch->mapUrl() }}" target="_blank" rel="noopener" class="text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Map</a>
            @elseif ($log->isManual())
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Entered by Operations{{ $log->manual_reason ? ': '.$log->manual_reason : '' }}</p>
            @endif
        @else
            <p class="text-gray-500 dark:text-gray-400">{{ $what === 'Clock out' ? $this->missingClockOut($log) : '—' }}</p>
        @endif
    </div>
</div>
