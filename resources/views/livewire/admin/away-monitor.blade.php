<div wire:poll.60s class="space-y-6">
    @php
        $now = \Carbon\CarbonImmutable::now();
        $isOffice = $scope === 'office';
        $filtering = trim($search) !== '' || $reason !== '' || $region !== '';
        $counts = $this->statusCounts;
    @endphp
        <x-ui.card :title="$isOffice ? 'Office staff out of the office' : 'Away from post'" :description="($isOffice ? 'Office staff who stepped out, and why.' : 'Guards who said they\'re leaving the post, and why.').' Each says when they\'ll be back and can add time. Running late is only flagged in amber; '.($isOffice ? 'the President or Vice President is' : 'Operations is').' alerted after '.config('attendance.away.location_alert_minutes').' min with no location, or '.config('attendance.away.late_alert_minutes').' min past their time.'">
            <x-slot:actions>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name…" aria-label="Search name" class="ui-field mt-0! sm:w-44">
                <x-ui.select wire:model.live="reason" aria-label="Filter by reason">
                    <option value="">All reasons</option>
                    @foreach (\App\Enums\AwayReason::for($isOffice) as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                @if ($this->filtersByRegion())
                    <x-ui.select wire:model.live="region" aria-label="Filter by region">
                        <option value="">All regions</option>
                        @foreach (\App\Enums\Region::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
            </x-slot:actions>

            {{-- Status tabs: who needs a call first. --}}
            <div class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-gray-200 px-4 dark:border-gray-800" role="tablist" aria-label="Status">
                @foreach (\App\Livewire\Admin\AwayMonitor::STATUSES as $value => $label)
                    <button type="button" role="tab" wire:click="$set('status', '{{ $value }}')" aria-selected="{{ $status === $value ? 'true' : 'false' }}"
                        @class(['-mb-px inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap', 'border-blue-600 text-blue-600 dark:text-blue-400' => $status === $value, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $status !== $value])>
                        {{ $label }}
                        @php $countTone = $counts[$value] === 0 ? 'gray' : match ($value) { 'attention', 'overdue' => 'red', 'late' => 'amber', default => 'gray' }; @endphp
                        <span @class(['rounded-full px-1.5 text-xs', 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300' => $countTone === 'red', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' => $countTone === 'amber', 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => $countTone === 'gray'])>{{ $counts[$value] }}</span>
                    </button>
                @endforeach
            </div>

            @php
                // Shared by the phone cards and the table.
                $timeBadge = function (\App\Models\AwayLog $away) use ($now): array {
                    $past = $away->minutesPastExpected($now);

                    return match (true) {
                        $away->approved_at !== null => ['blue', $away->minutesAway($now).' min · approved'],
                        $away->isLate($now) => ['red', $past.' min past their time'],
                        $past > 0 => ['amber', $past.' min past their time'],
                        default => ['gray', $away->minutesAway($now).' min out'],
                    };
                };
                $distance = fn (?int $m) => $m === null ? null : ($m < 1000 ? $m.' m' : round($m / 1000, 1).' km');
                $placeOf = fn (\App\Models\AwayLog $away) => $isOffice ? ($away->user->employeeProfile?->position?->label() ?? 'Office') : ($away->timeLog->dutyAssignment?->placeLabel() ?? '—');
                $emptyText = $filtering || $status !== 'all' ? 'Nobody out matches these filters.' : ($isOffice ? 'Every office staff member on duty is in the office.' : 'Everyone on duty is at their post.');
            @endphp

            {{-- Phones: one card per person, no sideways scrolling. --}}
            <ul class="divide-y divide-gray-100 sm:hidden dark:divide-gray-800">
                @forelse ($this->awayNow as $away)
                    @php
                        [$timeTone, $timeText] = $timeBadge($away);
                        $spot = $away->latestLocation;
                    @endphp
                    <li wire:key="away-card-{{ $away->id }}" class="space-y-2 px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <x-person-link :user="$away->user" />
                                <p class="truncate text-xs text-gray-500">{{ $placeOf($away) }}</p>
                            </div>
                            @if (! $away->approved_at && $away->user_id !== auth()->id())
                                <button type="button" wire:click="approve({{ $away->id }})" wire:confirm="Approve {{ $away->user->name }}'s time away?" class="shrink-0 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Approve</button>
                            @endif
                        </div>
                        <p class="text-sm text-gray-700 dark:text-gray-200">{{ $away->reason->label() }}@if ($away->note)<span class="text-gray-500"> · {{ $away->note }}</span>@endif</p>
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                            <span>{{ $away->left_at->format('g:i A') }} → back by {{ $away->expectedBackAt()->format('g:i A') }}</span>
                            <x-ui.badge :tone="$timeTone">{{ $timeText }}</x-ui.badge>
                            @if ($away->isLocationOverdue($now)) <x-ui.badge tone="red">Location overdue</x-ui.badge> @endif
                        </div>
                        <button type="button" wire:click="viewAway({{ $away->id }})" class="flex w-full items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-left text-xs dark:bg-gray-800/60">
                            <span class="text-gray-600 dark:text-gray-300">{{ $spot ? 'Last seen '.$spot->recorded_at->format('g:i A').($distance($spot->distance_meters) ? ' · '.$distance($spot->distance_meters).($isOffice ? ' from the office' : ' from post') : '') : 'Location not shared yet' }}</span>
                            <span class="inline-flex shrink-0 items-center gap-1 font-medium text-blue-600 dark:text-blue-400"><x-icon name="map-pin" class="h-3.5 w-3.5" /> View location</span>
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-sm text-gray-600 dark:text-gray-300">{{ $emptyText }}</li>
                @endforelse
            </ul>

            <div class="hidden overflow-x-auto sm:block">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">{{ $isOffice ? 'Staff' : 'Guard' }}</th><th class="ui-th">Reason</th><th class="ui-th">Time out</th><th class="ui-th">Last location</th><th class="ui-th">{{ $isOffice ? 'Position' : 'Post' }}</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->awayNow as $away)
                            @php [$timeTone, $timeText] = $timeBadge($away); @endphp
                            <tr wire:key="away-{{ $away->id }}">
                                <td class="ui-td"><x-person-link :user="$away->user" /></td>
                                <td class="ui-td">
                                    <p>{{ $away->reason->label() }}</p>
                                    @if ($away->note) <p class="max-w-64 truncate text-xs text-gray-500" title="{{ $away->note }}">{{ $away->note }}</p> @endif
                                </td>
                                <td class="ui-td whitespace-nowrap">
                                    <p>{{ $away->left_at->format('g:i A') }} <span class="text-gray-400">→</span> back by {{ $away->expectedBackAt()->format('g:i A') }}</p>
                                    <x-ui.badge :tone="$timeTone">{{ $timeText }}</x-ui.badge>
                                    @if ($away->extensions_count) <span class="text-xs text-gray-500">· added time {{ $away->extensions_count === 1 ? 'once' : $away->extensions_count.'×' }}</span> @endif
                                </td>
                                <td class="ui-td whitespace-nowrap">
                                    @php
                                        $spot = $away->latestLocation;
                                        $far = $spot?->distance_meters;
                                    @endphp
                                    <button type="button" wire:click="viewAway({{ $away->id }})" class="group text-left">
                                        @if ($spot)
                                            <span class="block text-gray-800 group-hover:text-blue-600 dark:text-gray-100">{{ $spot->recorded_at->format('g:i A') }}{{ $far !== null ? ' · '.($far < 1000 ? $far.' m' : round($far / 1000, 1).' km').($isOffice ? ' from office' : ' from post') : '' }}</span>
                                        @else
                                            <span class="block text-xs text-gray-500">Not shared yet</span>
                                        @endif
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 group-hover:text-blue-500 dark:text-blue-400"><x-icon name="map-pin" class="h-3.5 w-3.5" /> View location</span>
                                    </button>
                                    @if ($away->isLocationOverdue($now)) <x-ui.badge tone="red" class="mt-1">Location overdue</x-ui.badge> @endif
                                </td>
                                <td class="ui-td">{{ $placeOf($away) }}</td>
                                <td class="ui-td text-right whitespace-nowrap">
                                    @if (! $away->approved_at && $away->user_id !== auth()->id())
                                        <button type="button" wire:click="approve({{ $away->id }})" wire:confirm="Approve {{ $away->user->name }}'s time away?" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Approve</button>
                                    @elseif ($away->approver)
                                        <span class="text-xs text-gray-500">by {{ $away->approver->name }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="ui-td py-6 text-center">{{ $emptyText }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        @php
            $back = $this->awayToday;
            $shownDay = $this->shownDay();
            $isToday = $shownDay->isToday();
        @endphp
        {{-- The record: today's finished absences, or any earlier day's (the history). --}}
        <x-ui.card :title="($isToday ? ($isOffice ? 'Back in the office today' : 'Back at post today') : 'Time away on '.$shownDay->format('D, M j, Y')).' · '.$back->total()" :description="$filtering ? 'Matching the filters above.' : ($isToday ? 'Finished absences, latest first. Pick a day to see earlier ones.' : 'Finished absences of that day, latest first.')">
            <x-slot:actions>
                <div class="flex items-center gap-1">
                    <button type="button" wire:click="previousDay" aria-label="Previous day" class="rounded-md p-2 text-gray-500 hover:bg-gray-50 hover:text-gray-700 dark:hover:bg-white/5 dark:hover:text-gray-100"><x-icon name="chevron-left" class="h-4 w-4" /></button>
                    <label for="away-day-{{ $scope }}" class="sr-only">Day</label>
                    <input id="away-day-{{ $scope }}" type="date" value="{{ $shownDay->toDateString() }}" max="{{ today()->toDateString() }}"
                        x-on:change="$wire.set('day', $event.target.value === @js(today()->toDateString()) ? '' : $event.target.value)" class="ui-field mt-0! w-40">
                    <button type="button" wire:click="nextDay" aria-label="Next day" @disabled($isToday) class="rounded-md p-2 text-gray-500 hover:bg-gray-50 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-white/5 dark:hover:text-gray-100"><x-icon name="chevron-right" class="h-4 w-4" /></button>
                    @unless ($isToday)
                        <button type="button" wire:click="$set('day', '')" class="ml-1 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Today</button>
                    @endunless
                </div>
            </x-slot:actions>

            @if ($back->total() === 0)
                <p class="px-4 py-6 text-center text-sm text-gray-600 dark:text-gray-300">{{ $isToday ? 'Nobody has come back yet today.' : 'No time away on that day'.($filtering ? ' matching the filters.' : '.') }}</p>
            @else
                {{-- Phones: one card per absence. --}}
                <ul class="divide-y divide-gray-100 sm:hidden dark:divide-gray-800">
                    @foreach ($back as $away)
                        @php [$backTone, $backText] = $away->outcome($now); @endphp
                        <li wire:key="back-card-{{ $away->id }}" class="space-y-1.5 px-4 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <x-person-link :user="$away->user" class="min-w-0 truncate" />
                                <button type="button" wire:click="viewAway({{ $away->id }})" class="shrink-0 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">View</button>
                            </div>
                            <p class="text-sm text-gray-700 dark:text-gray-200">{{ $away->reason->label() }} <span class="text-gray-500">· {{ $away->left_at->format('g:i') }}–{{ $away->returned_at?->format('g:i A') }} ({{ $away->minutesAway($now) }} min)</span></p>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge :tone="$backTone">{{ $backText }}</x-ui.badge>
                                @if ($away->returned_distance_meters !== null && $away->returned_distance_meters > 500)
                                    <span class="text-xs text-amber-700 dark:text-amber-400">Back tapped {{ round($away->returned_distance_meters / 1000, 1) }} km from {{ $isOffice ? 'the office' : 'post' }}</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="hidden overflow-x-auto sm:block">
                    <table class="ui-table">
                        <thead><tr><th class="ui-th">{{ $isOffice ? 'Staff' : 'Guard' }}</th><th class="ui-th">Reason</th><th class="ui-th">Out</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($back as $away)
                                @php [$backTone, $backText] = $away->outcome($now); @endphp
                                <tr wire:key="back-{{ $away->id }}">
                                    <td class="ui-td"><x-person-link :user="$away->user" /></td>
                                    <td class="ui-td">{{ $away->reason->label() }}</td>
                                    <td class="ui-td whitespace-nowrap">
                                        {{ $away->left_at->format('g:i') }}–{{ $away->returned_at?->format('g:i A') }} <span class="text-xs text-gray-500">({{ $away->minutesAway($now) }} min)</span>
                                        @if ($away->returned_distance_meters !== null && $away->returned_distance_meters > 500)
                                            <p class="text-xs text-amber-700 dark:text-amber-400">Back tapped {{ round($away->returned_distance_meters / 1000, 1) }} km from {{ $isOffice ? 'the office' : 'post' }}</p>
                                        @endif
                                    </td>
                                    <td class="ui-td"><x-ui.badge :tone="$backTone">{{ $backText }}</x-ui.badge></td>
                                    <td class="ui-td text-right"><button type="button" wire:click="viewAway({{ $away->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">View</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($back->hasPages())
                <x-slot:footer>{{ $back->links() }}</x-slot:footer>
            @endif
        </x-ui.card>

    @if ($this->viewingAway && $this->trail)
        @php
            $away = $this->viewingAway;
            $trail = $this->trail;
            $now = \Carbon\CarbonImmutable::now();
            $meters = fn (?int $m) => $m === null ? null : ($m < 1000 ? $m.' m' : round($m / 1000, 1).' km');
            $latest = $trail['latest'];
            $place = $isOffice ? 'the office' : 'post';
            $mapData = [
                'post' => $trail['post'],
                'points' => array_map(fn ($point) => ['lat' => $point['lat'], 'lng' => $point['lng'], 'accuracy' => $point['accuracy'], 'label' => $point['label'], 'kind' => $point['kind']], $trail['points']),
            ];

            // The verdict, in the list's colours: what a supervisor needs at a glance.
            [$statusTone, $statusText] = $away->outcome($now);
            $backTitle = $away->auto_closed_at ? 'Closed at duty end (never ended)' : ($isOffice ? 'Back at the office' : 'Back at post');
            $firstSaid = $away->extensions->first()->previous_back_at ?? $away->expectedBackAt();
            $added = $away->extensions->count();

            // The timeline, newest first, with the minutes since the entry before (long silences stand out).
            $entries = [];
            foreach ($trail['points'] as $index => $point) {
                $previous = $trail['points'][$index - 1] ?? null;
                $entries[] = $point + [
                    'index' => $index,
                    'gap' => $previous ? (int) $previous['at']->diffInMinutes($point['at']) : null,
                    'title' => match ($point['kind']) {
                        'left' => $isOffice ? 'Left the office' : 'Left the post',
                        'returned' => $backTitle,
                        'extended' => 'Needed more time',
                        default => 'Shared location',
                    },
                ];
            }
            $entries = array_reverse($entries);
            $silentAfter = (int) config('attendance.away.location_alert_minutes');
        @endphp
        <x-ui.modal :title="$away->reason->label().' · '.$away->left_at->format('D, M j')" close="closeAway" max-width="4xl">
            {{-- At a glance --}}
            <div class="mb-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                <x-ui.badge :tone="$statusTone">{{ $statusText }}</x-ui.badge>
                <span>{{ $away->minutesAway($now) }} min out</span>
                <span class="text-gray-300 dark:text-gray-600">·</span>
                <span>said back by {{ $away->expectedBackAt()->format('g:i A') }}</span>
                @if ($added > 0)
                    <span class="text-gray-300 dark:text-gray-600">·</span>
                    <span>added time {{ $added === 1 ? 'once' : $added.'×' }}</span>
                @endif
            </div>

            {{-- Map and timeline on the left; on a phone: map, then the summary, then the timeline. --}}
            <div class="grid grid-cols-1 gap-5 md:grid-cols-5">
                @if ($away->locations_pruned_at)
                    {{-- Past the retention period: the trail is gone, the record stays. --}}
                    <div class="flex h-full min-h-40 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 p-6 text-center md:col-span-3 md:col-start-1 md:row-start-1 dark:border-gray-700">
                        <x-icon name="lock" class="h-6 w-6 text-gray-400" />
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">Location trail deleted</p>
                        <p class="max-w-sm text-xs text-gray-500">Locations are kept for {{ config('attendance.away.location_retention_days') }} days (Data Privacy Act), then deleted on {{ $away->locations_pruned_at->format('M j, Y') }}. The reason, times and approval below are kept with the attendance records.</p>
                    </div>
                @else
                {{-- The map: wire:ignore, so a Livewire refresh doesn't wipe Leaflet's DOM. --}}
                <div class="md:col-span-3 md:col-start-1 md:row-start-1" wire:ignore wire:key="away-map-{{ $away->id }}-{{ count($trail['points']) }}">
                    <div x-data="awayMap(@js($mapData))" x-on:away-map-focus.window="focus($event.detail)">
                        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                            <div x-ref="map" class="h-72 w-full bg-gray-100 sm:h-80 dark:bg-gray-800"></div>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                @if ($trail['post'])
                                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-blue-600"></span> {{ $isOffice ? 'Office' : 'Post' }}</span>
                                @endif
                                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-600"></span> Latest · circle = GPS accuracy</span>
                                @if (count(array_filter($trail['points'], fn ($point) => $point['lat'] !== null)) > 1)
                                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-gray-500"></span> Earlier</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-3">
                                <button type="button" x-on:click="showTrail()" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Whole trail</button>
                                <template x-if="hasPost">
                                    <button type="button" x-on:click="showPost()" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300" x-text="postFar ? @js(($isOffice ? 'Office' : 'Post').' (far)') : @js($isOffice ? 'Office' : 'Post')"></button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                {{-- Where they are, and the details --}}
                <div class="space-y-4 md:col-span-2 md:col-start-4 md:row-span-2 md:row-start-1">
                    {{-- Who: the name opens their employee record. --}}
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-semibold text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">{{ $away->user->initials() }}</span>
                        <div class="min-w-0">
                            <x-person-link :user="$away->user" class="block truncate" />
                            <p class="truncate text-xs text-gray-500">{{ $isOffice ? ($away->user->employeeProfile?->position?->label() ?? 'Office staff') : ($away->timeLog->dutyAssignment?->placeLabel() ?? '—') }}</p>
                        </div>
                    </div>

                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/60">
                        <p class="text-xs font-semibold tracking-wide text-gray-400 uppercase">{{ $away->returned_at ? $backTitle : 'Last seen' }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">
                            {{ $latest['at']->format('g:i A') }}
                            @unless ($away->returned_at) <span class="text-sm font-normal text-gray-500">· {{ (int) $latest['at']->diffInMinutes($now) }} min ago</span> @endunless
                        </p>
                        @if ($meters($latest['distance']))
                            <p class="text-sm text-gray-700 dark:text-gray-200">{{ $meters($latest['distance']) }} from {{ $trail['post']['name'] ?? 'the post' }}</p>
                        @endif
                        @if ($trail['address'])
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $trail['address'] }}</p>
                        @endif
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <x-gps-accuracy :meters="$latest['accuracy']" />
                            @if ($latest['mapUrl'])
                                <a href="{{ $latest['mapUrl'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300"><x-icon name="map-pin" class="h-4 w-4" /> Open in Google Maps</a>
                            @endif
                        </div>
                    </div>

                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-xs text-gray-400">Out</dt>
                            <dd class="text-gray-800 dark:text-gray-100">{{ $away->left_at->format('g:i A') }} → {{ $away->returned_at?->format('g:i A') ?? 'now' }} <span class="text-gray-500">({{ $away->minutesAway($now) }} min)</span></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-400">Said back by</dt>
                            <dd class="text-gray-800 dark:text-gray-100">
                                {{ $away->expectedBackAt()->format('g:i A') }}
                                @if ($added > 0) <span class="text-gray-500">(first said {{ $firstSaid->format('g:i A') }}; added {{ \App\Enums\AwayReason::durationLabel((int) $away->extensions->sum('minutes')) }})</span> @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-400">Note</dt>
                            <dd class="text-gray-800 dark:text-gray-100">{{ $away->note ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>

                {{-- The trail, newest first. A spot with a position is a button that shows it on the map. --}}
                <div class="md:col-span-3 md:col-start-1 md:row-start-2">
                    <p class="text-xs font-semibold tracking-wide text-gray-400 uppercase">Timeline</p>
                    <ol class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($entries as $entry)
                            @php
                                $isLatest = $entry['lat'] !== null && $entry['lat'] === $latest['lat'] && $entry['at']->equalTo($latest['at']);
                                $detail = array_values(array_filter([
                                    $entry['kind'] === 'extended' ? $entry['label'] : null,
                                    $entry['kind'] === 'left' ? 'said back by '.$firstSaid->format('g:i A') : null,
                                    $meters($entry['distance']) ? $meters($entry['distance']).' from '.$place : null,
                                    $entry['kind'] !== 'extended' && $entry['lat'] === null ? 'No GPS position' : null,
                                ]));
                            @endphp
                            <li class="flex items-start gap-3 py-2">
                                {{-- What happened, as an icon: left, shared a location, needed more time, came back. --}}
                                <span @class([
                                    'flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                                    'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' => $isLatest,
                                    'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300' => ! $isLatest && $entry['kind'] === 'returned',
                                    'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => ! $isLatest && $entry['kind'] !== 'returned',
                                ]) aria-hidden="true">
                                    <x-icon :name="match ($entry['kind']) { 'left' => 'logout', 'returned' => 'login', 'extended' => 'clock-plus', default => 'map-pin' }" class="h-4 w-4" />
                                </span>
                                <div class="min-w-0 flex-1 text-sm">
                                    @if ($entry['lat'] !== null)
                                        <button type="button" x-on:click="$dispatch('away-map-focus', {{ $entry['index'] }})" class="group text-left" aria-label="Show {{ $entry['at']->format('g:i A') }} on the map">
                                            <span class="text-gray-800 group-hover:text-blue-600 dark:text-gray-100">{{ $entry['at']->format('g:i A') }} · {{ $entry['title'] }}</span>
                                        </button>
                                    @else
                                        <span class="text-gray-800 dark:text-gray-100">{{ $entry['at']->format('g:i A') }} · {{ $entry['title'] }}</span>
                                    @endif
                                    @if ($detail !== [] || $entry['accuracy'])
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-gray-500">
                                            @if ($entry['accuracy']) <x-gps-accuracy :meters="$entry['accuracy']" dot /> @endif
                                            <span>{{ implode(' · ', $detail) }}</span>
                                        </p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-2 text-xs">
                                    @if ($entry['gap'] !== null && $entry['gap'] > 0)
                                        <span @class(['tabular-nums', 'font-medium text-amber-700 dark:text-amber-400' => $entry['gap'] >= $silentAfter, 'text-gray-400' => $entry['gap'] < $silentAfter]) title="Minutes since the entry before">+{{ $entry['gap'] }} min</span>
                                    @endif
                                    @if ($entry['mapUrl'])
                                        <a href="{{ $entry['mapUrl'] }}" target="_blank" rel="noopener" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-blue-600 dark:hover:bg-gray-800 dark:hover:text-blue-400" aria-label="Open {{ $entry['at']->format('g:i A') }} in Google Maps" title="Open in Google Maps"><x-icon name="arrow-top-right-on-square" class="h-3.5 w-3.5" /></a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>

            @if (! $away->returned_at && ! $away->approved_at && $away->user_id !== auth()->id())
                <x-slot:footer>
                    <x-ui.button type="button" wire:click="approve({{ $away->id }})" wire:confirm="Approve {{ $away->user->name }}'s time away?">Approve time away</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.modal>
    @endif

</div>
