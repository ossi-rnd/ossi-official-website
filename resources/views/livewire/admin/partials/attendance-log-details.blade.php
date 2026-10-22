{{-- Both punches of one shift in full: selfie, time, address, GPS and its accuracy. Shared by the
     table (laptop) and the cards (phone). Needs $log, $employee, $in, $out. --}}
@if ($log->isManual())
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">Entered by Operations (no selfie or GPS){{ $log->manual_reason ? ': '.$log->manual_reason : '' }}.</p>
@endif
<div class="grid gap-4 sm:grid-cols-2">
    @foreach ([[$in, 'Clock in'], [$out, 'Clock out']] as [$punch, $what])
        <div class="flex gap-3">
            @if ($punch)
                <button type="button" x-on:click="open = true; photo = @js($punch->photoUrl()); caption = @js($employee->name.' · '.$what.' · '.$punch->punched_at->format('M j, Y g:i A'))"
                    class="block h-28 w-20 shrink-0 overflow-hidden rounded-lg border border-gray-200 bg-gray-100 hover:ring-2 hover:ring-blue-400 dark:border-gray-700 dark:bg-gray-800 dark:hover:ring-blue-500" aria-label="View {{ strtolower($what) }} selfie">
                    <img src="{{ $punch->photoUrl() }}" alt="" class="h-full w-full object-cover" loading="lazy">
                </button>
            @endif
            <dl class="min-w-0 space-y-1 text-sm">
                <dt class="text-xs font-medium tracking-wide text-gray-400 uppercase">{{ $what }}</dt>
                @if ($punch)
                    <dd class="text-gray-800 dark:text-gray-100">{{ $punch->punched_at->format('M j, Y g:i A') }}</dd>
                    <dd class="break-words text-gray-600 dark:text-gray-300">{{ $punch->address ?? 'Address unavailable' }}</dd>
                    <dd>
                        <a href="{{ $punch->mapUrl() }}" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ number_format((float) $punch->latitude, 5) }}, {{ number_format((float) $punch->longitude, 5) }}</a>
                        @if ($punch->accuracy_meters) <span class="text-xs text-gray-500"> · <x-gps-accuracy :meters="$punch->accuracy_meters" plain /></span> @endif
                    </dd>
                    {{-- The phone: a guard's company phone, or another one (flagged, never refused). --}}
                    <dd class="text-gray-600 dark:text-gray-300">
                        <x-icon name="phone" class="inline h-4 w-4 align-text-bottom text-gray-400" /> {{ $punch->device_label ?? 'Phone not recorded' }}
                        @if ($punch->other_device)
                            <x-ui.badge tone="amber" class="ml-1">Other phone</x-ui.badge>
                            <span class="mt-0.5 block text-xs text-gray-500">{{ $employee->device ? 'Their phone: '.($employee->device->label ?? 'a phone').'.' : 'No phone registered for them now.' }}</span>
                            @if ($punch->device_hash && $punch->device_hash !== $employee->device?->token_hash && \App\Models\UserDevice::canManage(auth()->user(), $employee))
                                <button type="button" wire:click="makeTheirPhone({{ $punch->id }})" wire:confirm="Make this phone ({{ $punch->device_label ?? 'unknown' }}) {{ $employee->name }}'s company phone? From now on it won't be flagged."
                                    class="mt-1 text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Make this their phone</button>
                            @endif
                        @endif
                    </dd>
                @else
                    <dd class="text-gray-500">{{ $what === 'Clock out' && $log->clock_out_at === null ? 'Not clocked out yet.' : ($log->isManual() ? 'Entered by hand, no selfie.' : '—') }}</dd>
                @endif
            </dl>
        </div>
    @endforeach
</div>
