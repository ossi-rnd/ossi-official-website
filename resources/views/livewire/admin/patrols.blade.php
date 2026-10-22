<div class="space-y-6">
    <div class="flex gap-1 border-b border-gray-200 dark:border-gray-800" role="tablist">
        @foreach (['live' => 'On duty now', 'checkpoints' => 'Checkpoints'] as $value => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $value }}')" aria-selected="{{ $tab === $value ? 'true' : 'false' }}"
                @class(['-mb-px border-b-2 px-3 py-2.5 text-sm font-medium', 'border-blue-600 text-blue-600 dark:text-blue-400' => $tab === $value, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $tab !== $value])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'live')
        @php $now = \Carbon\CarbonImmutable::now(); @endphp
        <div wire:poll.60s class="space-y-6">
            <livewire:admin.away-monitor scope="field" />

            <x-ui.card title="Roving guards on patrol" description="Each round, every checkpoint must be scanned. A missed round alerts Operations.">
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th class="ui-th">Guard</th><th class="ui-th">Site</th><th class="ui-th">Rounds</th><th class="ui-th">Last scan</th><th class="ui-th"><span class="sr-only">Scans</span></th></tr></thead>
                        <tbody>
                            @forelse ($this->patrolling as $row)
                                @php $rounds = collect($row['rounds']); $current = $rounds->firstWhere('state', 'current'); @endphp
                                <tr wire:key="patrol-{{ $row['log']->id }}">
                                    <td class="ui-td"><x-person-link :user="$row['log']->user" /></td>
                                    <td class="ui-td">{{ $row['log']->dutyAssignment?->placeLabel() }}</td>
                                    <td class="ui-td">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($rounds as $round)
                                                <span title="Round {{ $round['index'] }} · {{ $round['from']->format('g:i') }}–{{ $round['to']->format('g:i A') }}{{ $round['missing'] ? ' · missing: '.implode(', ', $round['missing']) : '' }}{{ $round['suspicious'] ? ' · looks faked: '.$round['suspicious'] : '' }}"
                                                    @class(['rounded px-1.5 py-0.5 text-xs font-medium', 'ring-2 ring-red-500' => $round['suspicious'], 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' => $round['state'] === 'done', 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' => $round['state'] === 'missed', 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' => $round['state'] === 'current', 'bg-gray-100 text-gray-400 dark:bg-gray-800' => $round['state'] === 'upcoming'])>{{ $round['index'] }}{{ $round['suspicious'] ? ' !' : '' }}</span>
                                            @endforeach
                                        </div>
                                        @if ($current) <p class="mt-1 text-xs text-gray-500">Now: {{ count($current['scanned']) }} of {{ $row['total'] }} scanned</p> @endif
                                        {{-- Scanned, but not walked: the route took seconds, or came from one spot. --}}
                                        @foreach ($rounds->whereNotNull('suspicious') as $round)
                                            <p class="mt-1 flex items-center gap-1 text-xs font-medium text-red-600 dark:text-red-400"><x-icon name="warning" class="h-3.5 w-3.5" /> Round {{ $round['index'] }} looks faked: {{ $round['suspicious'] }}</p>
                                        @endforeach
                                        @if ($row['total'] === 0) <p class="text-xs text-amber-700 dark:text-amber-400">This client has no checkpoints yet.</p> @endif
                                    </td>
                                    <td class="ui-td whitespace-nowrap">{{ $row['last'] ? $row['last']->checkpoint->name.' · '.$row['last']->scanned_at->format('g:i A') : '—' }}</td>
                                    <td class="ui-td text-right">
                                        @if ($row['last'])
                                            <button type="button" wire:click="viewScans({{ $row['log']->id }})" class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300"><x-icon name="camera" class="h-4 w-4" /> Scans</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="ui-td py-6 text-center">No roving guard is clocked in. Mark a duty "Roving patrol" on the Duty Roster.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <x-slot:footer><p class="text-xs text-gray-500">Blue: round done · Red: missed · Amber: in progress · Gray: later · Red ring "!": looks faked (scanned in seconds, or all from one spot). Refreshes every minute.</p></x-slot:footer>
            </x-ui.card>
        </div>
    @else
        <x-ui.card title="Checkpoints" description="QR stickers at a client's site. Roving guards scan each one every round; the scan keeps the time, GPS and a photo of the spot.">
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.select wire:model.live="clientId" aria-label="Client">
                        @foreach ($this->clients as $option)
                            <option value="{{ $option->id }}">{{ $option->name }} ({{ $option->checkpoints_count }})</option>
                        @endforeach
                    </x-ui.select>
                    @if ($this->client)
                        <x-ui.button :href="route('patrols.print', $this->client)" variant="secondary" size="sm" target="_blank"><x-icon name="download" class="h-4 w-4" /> Print QR stickers</x-ui.button>
                        <x-ui.button type="button" variant="dark" size="sm" wire:click="createCheckpoint"><x-icon name="plus" class="h-4 w-4" /> Add checkpoint</x-ui.button>
                    @endif
                </div>
            </x-slot:actions>

            @if ($this->client)
                <form wire:submit="saveInterval" class="flex flex-wrap items-end gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <div class="w-44"><x-ui.input label="A full round every (min)" type="number" min="30" max="720" step="15" wire:model="interval" /></div>
                    <x-ui.button variant="secondary" size="sm">Save</x-ui.button>
                    <p class="text-xs text-gray-500">e.g. 120 = every 2 hours from the start of the duty.</p>
                </form>

                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th class="ui-th">Checkpoint</th><th class="ui-th">Location</th><th class="ui-th">Scans</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            @forelse ($this->checkpoints as $checkpoint)
                                <tr wire:key="cp-{{ $checkpoint->id }}">
                                    <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $checkpoint->name }}</td>
                                    <td class="ui-td text-xs">
                                        @if ($checkpoint->hasLocation())
                                            <span class="inline-flex items-center gap-1 font-medium text-blue-700 dark:text-blue-300"><x-icon name="map-pin" class="h-3.5 w-3.5" /> Pinned</span>
                                            <span class="block text-gray-500">Scans within {{ config('attendance.patrol.checkpoint_radius_meters') }} m of the sticker</span>
                                        @else
                                            {{-- Unpinned: a photo of the sticker scans from anywhere on site (e.g. the guardhouse). --}}
                                            <span class="font-medium text-amber-700 dark:text-amber-400">Not pinned</span>
                                            <span class="block text-gray-500">{{ $this->client->hasGeofence() ? 'Scans anywhere on site. Pin it at the sticker.' : 'Scans anywhere. Pin it at the sticker.' }}</span>
                                        @endif
                                    </td>
                                    <td class="ui-td tabular-nums">{{ $checkpoint->scans_count }}</td>
                                    <td class="ui-td text-right whitespace-nowrap">
                                        <button type="button" wire:click="editCheckpoint({{ $checkpoint->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ $checkpoint->hasLocation() ? 'Edit' : 'Edit / pin' }}</button>
                                        <button type="button" wire:click="renewCheckpoint({{ $checkpoint->id }})" wire:confirm="Give {{ $checkpoint->name }} a new code? The sticker on the wall (and any photo of it) stops working until you print and put up the new one." class="ml-3 font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100">New sticker</button>
                                        <x-ui.row-delete wire:click="removeCheckpoint({{ $checkpoint->id }})" wire:confirm="Remove {{ $checkpoint->name }}? Its QR stops working.">Remove</x-ui.row-delete>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="ui-td py-8 text-center">No checkpoints at {{ $this->client->name }} yet. Add the spots a roving guard must visit, then print the QR stickers.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <p class="p-8 text-center text-sm text-gray-500">No clients{{ auth()->user()?->isRegionallyLimited() ? ' in your region' : '' }} yet.</p>
            @endif
        </x-ui.card>
    @endif

    {{-- A guard's scans today, with the camera frame of each: a photo of a screen shows. --}}
    @if ($viewingScansLogId !== null)
        @php $scans = $this->scans; @endphp
        <x-ui.modal :title="'Scans · '.($scans->first()?->user->name ?? '')" close="closeScans" max-width="2xl">
            <p class="mb-3 text-xs text-gray-500">Each scan keeps the camera frame that read the QR. A photo of a sticker on a phone (a screen's edge, glare, pixels) instead of the real one is a sign of cheating.</p>
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($scans as $scan)
                    <li wire:key="scan-{{ $scan->id }}" class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700" x-data="{ big: false }">
                        <button type="button" x-on:click="big = true" class="block w-full bg-gray-100 dark:bg-gray-800" aria-label="Enlarge the photo of {{ $scan->checkpoint->name }} at {{ $scan->scanned_at->format('g:i A') }}">
                            <img src="{{ route('patrol-scans.photo', $scan) }}" alt="" loading="lazy" class="aspect-square w-full object-cover">
                        </button>
                        <div class="p-2 text-xs">
                            <p class="font-medium text-gray-800 dark:text-gray-100">{{ $scan->checkpoint->name }}</p>
                            <p class="text-gray-500">{{ $scan->scanned_at->format('g:i:s A') }}{{ $scan->distance_meters !== null ? ' · '.$scan->distance_meters.' m away' : '' }}</p>
                        </div>
                        <template x-if="big">
                            {{-- Closes only with ✕ or Esc, like the selfie viewer. --}}
                            <div class="fixed inset-0 z-[70] flex items-center justify-center bg-black/80 p-4 pt-16" role="dialog" aria-modal="true" aria-label="Scan of {{ $scan->checkpoint->name }}" x-on:keydown.escape.window="big = false">
                                <button type="button" x-on:click="big = false" aria-label="Close" class="absolute top-4 right-4 rounded-full bg-white/10 p-2 text-white transition-colors hover:bg-white/25 focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none"><x-icon name="x" class="h-5 w-5" /></button>
                                <img src="{{ route('patrol-scans.photo', $scan) }}" alt="Scan of {{ $scan->checkpoint->name }}" class="max-h-full max-w-full rounded-lg">
                            </div>
                        </template>
                    </li>
                @endforeach
            </ul>
        </x-ui.modal>
    @endif

    @if ($checkpointFormOpen)
        <x-ui.modal :title="$editingCheckpointId ? 'Edit checkpoint' : 'Add checkpoint'" close="closeCheckpoint">
            <form id="checkpoint-form" wire:submit="saveCheckpoint" class="space-y-4">
                <x-ui.input label="Name" wire:model="checkpointName" placeholder="e.g. Parking B2 stairwell" />

                {{-- Pin it: stand at the sticker with a phone and tap. Then only scans near it count. --}}
                <div x-data="{
                        working: false, fix: null, note: null,
                        async pin() {
                            this.working = true; this.note = null; this.fix = null;
                            try {
                                const at = await window.currentPosition(@js((int) config('attendance.max_gps_accuracy_meters')), (accuracy) => { this.fix = accuracy; }, 20000);
                                $wire.set('checkpointCoordinates', at.latitude.toFixed(7) + ', ' + at.longitude.toFixed(7));
                                this.note = at.accuracy > 30 ? 'Pinned, but the GPS was rough (±' + Math.round(at.accuracy) + ' m). Pin again in a more open spot if you can.' : 'Pinned here (±' + Math.round(at.accuracy) + ' m).';
                            } catch (e) { this.note = 'Couldn\'t get your location: ' + e.message + '. Allow location for this site.'; }
                            this.working = false;
                        },
                    }" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">Location</p>
                            <p class="text-xs text-gray-500">Stand at the sticker and tap. Scans then count only within {{ config('attendance.patrol.checkpoint_radius_meters') }} m of it.</p>
                        </div>
                        <x-ui.button type="button" variant="secondary" size="sm" x-on:click="pin()" x-bind:disabled="working" class="shrink-0">
                            <x-icon name="map-pin" class="h-4 w-4" /> <span x-text="working ? (fix ? '±' + fix + ' m…' : 'Locating…') : 'Use my location here'"></span>
                        </x-ui.button>
                    </div>
                    <p x-show="note" x-text="note" x-cloak class="mt-2 text-xs text-gray-600 dark:text-gray-300"></p>
                    <div class="mt-3">
                        <x-ui.input label="Coordinates" wire:model="checkpointCoordinates" placeholder="14.5547, 121.0244 (or leave empty: anywhere on site)" />
                    </div>
                </div>
                <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">Renaming or moving the pin keeps the QR, so the sticker on the wall still works. Deep basements can have weak GPS: leave those unpinned.</p>
                <x-ui.input label="Order on the round" type="number" min="0" wire:model="checkpointOrder" />
            </form>
            <x-slot:footer>
                <x-ui.button form="checkpoint-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
