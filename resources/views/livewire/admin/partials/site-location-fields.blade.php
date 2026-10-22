{{-- Where people must be to clock in: coordinates + geofence, set at the site with the phone's GPS
     or on the map (site-picker.js). Shared by the client form ($who = 'Guards', $place = 'post') and
     the office form ($who = 'Office staff', $place = 'office'). Binds `coordinates` and `geofence_meters`. --}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
    <div class="sm:col-span-2"><x-ui.input :label="ucfirst($place).' coordinates'" wire:model="coordinates" placeholder="14.5547, 121.0244" /></div>
    <x-ui.input label="Geofence (m)" type="number" min="30" max="2000" wire:model="geofence_meters" />
</div>
<p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $who }} can only clock in and out within this distance of these coordinates. Best: at the {{ $place }}, tap <b>Use my current location</b>. Or click the map, drag the pin, or paste from Google Maps (right-click the site, click the numbers). Leave empty for no geofence (not recommended).</p>

<div x-data="sitePicker" x-on:fullscreenchange.document="fullscreenChanged()" x-on:keydown.escape.window="full && toggleFull()" class="space-y-2">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <x-ui.button type="button" variant="secondary" size="sm" x-on:click="locate()" x-bind:disabled="locating">
            <x-icon name="map-pin" class="h-4 w-4" /> <span x-text="locating ? 'Getting your location…' : 'Use my current location'">Use my current location</span>
        </x-ui.button>
        <p x-show="accuracy !== null" x-cloak class="text-xs text-gray-500 dark:text-gray-400">
            GPS precise to ±<span x-text="accuracy"></span> m<span x-show="rough" class="font-medium text-amber-700 dark:text-amber-400"> · rough: step outside or near a window and try again</span>
        </p>
    </div>
    <p x-show="locateError" x-cloak x-text="locateError" role="alert" class="text-sm text-red-600 dark:text-red-400"></p>
    <div wire:ignore>
        {{-- Full screen: the browser's own, or (iPhone) this box covering the screen. --}}
        <div x-ref="mapBox" class="relative" x-bind:class="full && 'fixed inset-0 z-[80] bg-white dark:bg-gray-900'">
            <div x-ref="map" class="w-full overflow-hidden border-gray-200 dark:border-gray-700" x-bind:class="full ? 'h-full' : 'h-56 rounded-lg border'"
                role="application" aria-label="Map of the {{ $place }}: click to place the pin, drag it to adjust. The circle is the geofence."></div>
            <button type="button" x-on:click="toggleFull()" x-bind:aria-pressed="full.toString()"
                class="absolute top-2.5 right-2.5 z-[1000] inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700">
                <x-icon name="arrows-pointing-out" class="h-4 w-4" x-show="! full" />
                <span x-text="full ? 'Done' : 'Full screen'">Full screen</span>
            </button>
        </div>
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">The blue circle is where {{ strtolower($who) }} can clock in. Make sure it covers the {{ $place }}.</p>
</div>
