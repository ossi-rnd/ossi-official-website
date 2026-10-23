<div class="lg:flex lg:items-start lg:gap-8">
    <div class="mx-auto w-full max-w-md lg:mx-0 lg:w-96 lg:shrink-0"
        x-data="{
            punchOpen: false,
            closing: false,
            closePunch() {
                if (! this.punchOpen || this.closing) return;
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                this.closing = true;
                setTimeout(() => { this.punchOpen = false; this.closing = false; }, reduce ? 0 : 150);
            },
        }"
        x-on:close-punch-modal.window="closePunch()">

        {{-- Today --}}
        <div class="ui-card rounded-2xl p-6 text-center">
            <p class="text-xs font-semibold tracking-wide text-gray-400 uppercase">{{ now()->format('l, F j, Y') }}</p>
            @if ($this->timeLog === null && $this->duty->refusal !== null)
                <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">Not on duty now</p>
            @else
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $this->duty->isRostered() || $this->timeLog?->duty_assignment_id ? 'Duty' : 'Usual shift' }}: {{ $this->shift->label() }} · {{ $this->shift->grace_minutes }} min grace
                </p>
                @if ($site)
                    <p class="text-xs text-gray-400">{{ $this->duty->duty?->placeLabel() ?? $site->name }}{{ $site->hasGeofence() ? ' · clock in within '.$site->geofence_meters.' m of the '.($site instanceof \App\Models\Office ? 'office' : 'post') : '' }}</p>
                @endif
            @endif

            <div class="my-5 grid grid-cols-3 divide-x divide-gray-100 rounded-xl bg-gray-50 py-4 dark:divide-gray-800 dark:bg-gray-800/50">
                @foreach ([
                    ['Clock In', $this->timeLog?->clock_in_at?->format('g:i A')],
                    ['Clock Out', $this->timeLog?->clock_out_at?->format('g:i A')],
                    ['Worked', ($minutes = $this->timeLog?->workedMinutes()) !== null ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : null],
                ] as [$label, $value])
                    <div class="px-1">
                        <p class="truncate text-[11px] font-medium tracking-wide text-gray-400 uppercase">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-800 tabular-nums sm:text-xl dark:text-gray-100">{{ $value ?? '—' }}</p>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-col items-center gap-2">
                @if ($this->timeLog)
                    <x-ui.status-badge :status="$this->timeLog->status" />
                    @if ($this->timeLog->late_minutes > 0)
                        <p class="text-xs text-amber-700 dark:text-amber-400">{{ \App\Support\Duration::minutes($this->timeLog->late_minutes) }} late</p>
                    @endif
                @endif

                @if ($this->nextPunch === null)
                    <p class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                        <x-icon name="check" class="h-4 w-4 text-green-600 dark:text-green-400" /> You're done for today
                    </p>
                @else
                    @if ($this->nextPunch === \App\Enums\PunchType::ClockIn && $this->duty->refusal !== null)
                        <x-ui.alert tone="blue" class="mt-3 w-full text-left">{{ $this->duty->refusal }}</x-ui.alert>
                    @endif
                    <button type="button" x-on:click="punchOpen = true" @disabled($this->nextPunch === \App\Enums\PunchType::ClockIn && $this->duty->refusal !== null)
                        @class([
                            'disabled:cursor-not-allowed disabled:opacity-40',
                            'mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl py-3 text-base font-semibold text-white transition-colors',
                            'bg-gray-900 hover:bg-gray-800 dark:bg-white dark:text-gray-800 dark:hover:bg-gray-200' => $this->nextPunch === \App\Enums\PunchType::ClockIn,
                            'bg-blue-600 hover:bg-blue-700' => $this->nextPunch === \App\Enums\PunchType::ClockOut,
                        ])>
                        <x-icon name="camera" class="h-5 w-5" /> {{ $this->nextPunch->label() }}
                    </button>
                @endif

                @if ($this->todayOvertime)
                    <p class="mt-1 flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                        Overtime requested ({{ intdiv($this->todayOvertime->requested_minutes, 60) }}h {{ $this->todayOvertime->requested_minutes % 60 }}m)
                        <x-ui.status-badge :status="$this->todayOvertime->status" />
                    </p>
                @else
                    <a href="{{ route('overtime', ['date' => today()->toDateString()]) }}" wire:navigate class="mt-1 inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                        <x-icon name="clock-plus" class="h-4 w-4" /> Request overtime
                    </a>
                @endif
            </div>
        </div>

        {{-- On duty: patrol rounds (roving duties) and leaving the post. --}}
        @if ($this->timeLog?->isOpen())
            @php
                $patrol = $this->patrol;
                $current = collect($patrol['rounds'])->first(fn ($round) => $round['state'] === 'current') ?? collect($patrol['rounds'])->first(fn ($round) => $round['state'] === 'upcoming');
                $missed = collect($patrol['rounds'])->where('state', 'missed')->count();
            @endphp

            <div class="ui-card mt-4 rounded-2xl p-4" x-data="{ scanOpen: false, awayOpen: false, extendOpen: false, extendMinutes: @js($awayExtensions[1] ?? $awayExtensions[0]), extendReason: '', working: false, sharing: false, error: null, fix: null, maxAccuracy: @js((int) config('attendance.max_gps_accuracy_meters')),
                async extend() {
                    this.working = true; this.error = null;
                    const result = await $wire.extendAway(this.extendMinutes, this.extendReason);
                    this.working = false;
                    if (result.ok) { this.extendOpen = false; this.extendReason = ''; } else { this.error = result.message; }
                },
                async back() {
                    this.working = true; this.error = null; this.fix = null;
                    // Never blocked by where they are: the spot is just recorded.
                    let at = { latitude: null, longitude: null, accuracy: null };
                    try { at = await window.currentPosition(this.maxAccuracy, (accuracy) => { this.fix = accuracy; }, 10000); } catch (e) {}
                    const result = await $wire.returnToPost(at.latitude, at.longitude, at.accuracy);
                    if (! result.ok) this.error = result.message;
                    this.working = false;
                },
                async share() {
                    this.sharing = true; this.error = null; this.fix = null;
                    try {
                        const at = await window.currentPosition(this.maxAccuracy, (accuracy) => { this.fix = accuracy; }, 15000);
                        const result = await $wire.updateLocation(at.latitude, at.longitude, at.accuracy);
                        if (! result.ok) this.error = result.message;
                    } catch (e) { this.error = 'Location error: ' + e.message + '. Allow location for this site.'; }
                    this.sharing = false;
                },
                async leave() {
                    this.working = true; this.error = null;
                    let at = { latitude: null, longitude: null };
                    try { at = await window.currentPosition(this.maxAccuracy, (accuracy) => { this.fix = accuracy; }, 8000); } catch (e) {}
                    const result = await $wire.leavePost(at.latitude, at.longitude);
                    this.working = false;
                    if (result.ok) { this.awayOpen = false; } else { this.error = result.message; }
                },
            }" x-on:close-scan-modal.window="scanOpen = false">

                @if ($this->away)
                    @php    
                        $now = \Carbon\CarbonImmutable::now();
                        $minutes = $this->away->minutesAway($now);
                        $backBy = $this->away->expectedBackAt();
                        $past = $this->away->minutesPastExpected($now);
                        $lastSeen = $this->away->lastSeenAt();
                        $locationDue = $this->away->isLocationDue($now);
                        $silent = $this->away->isLocationOverdue($now);
                        $every = (int) config('attendance.away.location_update_minutes');
                        $boss = \App\Support\Heads::label(auth()->user());
                    @endphp
                    {{-- Re-checks every minute, so the "share your location" reminder appears on time. --}}
                    <div wire:poll.60s class="hidden"></div>
                    <div class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        <p class="font-semibold">Away: {{ $this->away->reason->label() }}</p>
                        <p class="text-xs">Since {{ $this->away->left_at->format('g:i A') }} · {{ $minutes }} min · back by <b>{{ $backBy->format('g:i A') }}</b></p>
                        @if ($past > 0 && ! $this->away->approved_at)
                            <p class="mt-1 text-xs">Running late? That's fine: add time below so {{ $boss }} knows.</p>
                        @endif
                    </div>

                    {{-- "I need more time": no approval, just a heads-up with a reason. --}}
                    <button type="button" x-show="! extendOpen" x-on:click="extendOpen = true" class="mt-2 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Need more time?</button>
                    <div x-show="extendOpen" x-cloak class="mt-3 space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                        <p class="text-xs font-semibold tracking-wide text-gray-400 uppercase">Add time</p>
                        <div class="grid grid-cols-4 gap-1" role="radiogroup" aria-label="How much time">
                            @foreach ($awayExtensions as $extra)
                                <button type="button" role="radio" x-on:click="extendMinutes = {{ $extra }}" x-bind:aria-checked="(extendMinutes === {{ $extra }}).toString()"
                                    x-bind:class="extendMinutes === {{ $extra }} ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-200'"
                                    class="rounded-lg border py-2 text-sm font-medium">+{{ \App\Enums\AwayReason::durationLabel($extra) }}</button>
                            @endforeach
                        </div>
                        <label for="extend-reason" class="sr-only">Why</label>
                        <input id="extend-reason" type="text" x-model="extendReason" maxlength="200" placeholder="Why? e.g. traffic on EDSA, long queue" class="ui-field mt-0!">
                        <div class="flex gap-2">
                            <x-ui.button type="button" variant="secondary" x-on:click="extendOpen = false" class="flex-1">Cancel</x-ui.button>
                            <x-ui.button type="button" variant="secondary" x-on:click="extend()" x-bind:disabled="working" class="flex-1">Add time</x-ui.button>
                        </div>
                    </div>

                    @if ($locationDue)
                        <div @class(['mt-3 flex items-start gap-2 rounded-xl border bg-white p-3 text-sm dark:bg-gray-900', 'border-red-200 text-red-700 dark:border-red-500/30 dark:text-red-300' => $silent, 'border-amber-200 text-amber-800 dark:border-amber-500/30 dark:text-amber-300' => ! $silent]) role="status">
                            <x-icon name="map-pin" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                <b>Time to share your location.</b> Last shared {{ $lastSeen->format('g:i A') }}, {{ (int) $lastSeen->diffInMinutes($now) }} min ago.
                                {{ $silent ? ucfirst($boss).' has been told you haven\'t shared in a while.' : 'One tap is enough; it lets '.$boss.' see you\'re on your way.' }}
                            </span>
                        </div>
                    @else
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Location shared {{ $lastSeen->format('g:i A') }}{{ $this->away->latestLocation?->distance_meters !== null ? ' · '.($this->away->latestLocation->distance_meters < 1000 ? $this->away->latestLocation->distance_meters.' m' : round($this->away->latestLocation->distance_meters / 1000, 1).' km').($isOffice ? ' from the office' : ' from your post') : '' }}. Next by {{ $lastSeen->addMinutes($every)->format('g:i A') }}.</p>
                    @endif
                    <button type="button" x-on:click="share()" x-bind:disabled="sharing || working"
                        @class(['mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-semibold disabled:opacity-60', 'bg-blue-600 text-white hover:bg-blue-700' => $locationDue, 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800' => ! $locationDue])>
                        <x-icon name="map-pin" class="h-4 w-4" /> <span x-text="sharing ? (fix ? 'Getting your GPS position (±' + fix + ' m)…' : 'Getting your GPS position…') : 'Update my location'"></span>
                    </button>
                    <button type="button" x-on:click="back()" x-bind:disabled="working || sharing"
                        @class(['mt-2 inline-flex w-full items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-semibold disabled:opacity-60', 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800' => $locationDue, 'bg-blue-600 text-white hover:bg-blue-700' => ! $locationDue])>
                        <span x-text="working ? 'Saving…' : @js($isOffice ? 'Back at the office' : 'Back at post')"></span>
                    </button>
                @else
                    @if ($patrol['checkpoints']->isNotEmpty())
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-semibold tracking-wide text-gray-400 uppercase">Patrol</p>
                            @if ($missed > 0) <x-ui.badge tone="red">{{ $missed }} {{ str('round')->plural($missed) }} missed</x-ui.badge> @endif
                        </div>
                        @if ($current)
                            <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">Round {{ $current['index'] }}: {{ $current['from']->format('g:i') }}–{{ $current['to']->format('g:i A') }} · {{ count($current['scanned']) }} of {{ $patrol['checkpoints']->count() }} scanned</p>
                            <ul class="mt-2 grid grid-cols-2 gap-1 text-xs">
                                @foreach ($patrol['checkpoints'] as $checkpoint)
                                    <li @class(['flex items-center gap-1 rounded-md px-2 py-1', 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' => in_array($checkpoint->id, $current['scanned'], true), 'bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => ! in_array($checkpoint->id, $current['scanned'], true)])>
                                        @if (in_array($checkpoint->id, $current['scanned'], true)) <x-icon name="check" class="h-3 w-3" /> @endif {{ $checkpoint->name }}
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1 text-sm text-gray-500">All rounds for this duty are over.</p>
                        @endif
                        <button type="button" x-on:click="scanOpen = true" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gray-900 py-2.5 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-white dark:text-gray-800 dark:hover:bg-gray-200">
                            <x-icon name="camera" class="h-4 w-4" /> Scan checkpoint
                        </button>
                    @endif

                    <button type="button" x-on:click="awayOpen = ! awayOpen" class="mt-2 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-gray-300 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                        {{ $isOffice ? 'Stepping out of the office' : 'Leaving post' }}
                    </button>
                    <div x-show="awayOpen" x-cloak class="mt-3 space-y-3 text-left">
                        @php
                            // The reason picked (the first until they choose): the note's question and example, and the leave hint.
                            $chosenReason = \App\Enums\AwayReason::tryFrom($awayReason) ?? $awayReasons[0];
                        @endphp
                        {{-- No "usually 2 hrs": a suggested time becomes the plan. They say their own, and are held to it. --}}
                        <x-ui.select label="Why" wire:model.live="awayReason">
                            @foreach ($awayReasons as $reason)
                                <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                            @endforeach
                        </x-ui.select>
                        <div>
                            <x-ui.select label="How long will you be out?" wire:model="awayMinutes">
                                <option value="">Choose your time</option>
                                @foreach ($awayDurations as $duration)
                                    <option value="{{ $duration }}">{{ \App\Enums\AwayReason::durationLabel($duration) }}</option>
                                @endforeach
                            </x-ui.select>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">You're expected back by then. Back early is fine.</p>
                            {{-- Personal reasons only: a long official trip is still work, not leave. --}}
                            @if ($chosenReason->isPersonal())
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Out for more than {{ \App\Enums\AwayReason::durationLabel(\App\Enums\AwayReason::SUGGEST_LEAVE_AFTER_MINUTES) }}? File it as leave on <a href="{{ route('leave') }}" wire:navigate class="font-medium text-gray-700 underline decoration-gray-300 underline-offset-2 hover:text-blue-600 dark:text-gray-300 dark:decoration-gray-600 dark:hover:text-blue-400">My Leave</a> instead.</p>
                            @endif
                        </div>
                        {{-- The question follows the reason; optional for a guard's routine ones. --}}
                        <x-ui.input :label="$chosenReason->noteLabel().($chosenReason->needsNote() ? '' : ' (optional)')" wire:model="awayNote" maxlength="500" :placeholder="$chosenReason->noteExample($isOffice)" />
                        <x-ui.button type="button" x-on:click="leave()" x-bind:disabled="working" class="w-full">{{ $isOffice ? 'Mark me out' : 'Mark me away' }}</x-ui.button>
                    </div>
                @endif

                <p x-show="error" x-text="error" x-cloak class="mt-2 text-sm text-red-600 dark:text-red-400"></p>

                {{-- Checkpoint scanner. x-if: the back camera only runs while it's open. --}}
                <template x-if="scanOpen">
                    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                        <div class="relative w-full max-w-sm rounded-2xl bg-white p-4 shadow-xl dark:bg-gray-900" wire:ignore x-data="patrolScanner({ maxAccuracy: @js((int) config('attendance.max_gps_accuracy_meters')) })">
                            <button type="button" x-on:click="$dispatch('close-scan-modal')" aria-label="Close" class="absolute top-3 right-3 z-10 rounded-full bg-black/40 p-1.5 text-white hover:bg-black/60"><x-icon name="x" class="h-4 w-4" /></button>
                            <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Point the camera at the checkpoint's QR</p>
                            <div class="relative aspect-square w-full overflow-hidden rounded-xl bg-black">
                                <video x-ref="video" playsinline muted class="h-full w-full object-cover"></video>
                                <div class="pointer-events-none absolute inset-10 rounded-xl border-2 border-white/70"></div>
                            </div>
                            <p class="mt-3 text-center text-sm" x-bind:class="ok ? 'text-blue-700 dark:text-blue-300 font-semibold' : (error || failed ? 'text-red-600' : 'text-gray-500')"
                                x-text="error ?? message ?? (coords ? 'Looking for a QR… (GPS ±' + Math.round(coords.accuracy) + ' m)' : (locationError ?? 'Getting your GPS position…'))"></p>
                        </div>
                    </div>
                </template>
            </div>
        @endif
        @if ($this->upcoming->isNotEmpty())
            <div class="ui-card mt-4 rounded-2xl p-4">
                <p class="text-xs font-semibold tracking-wide text-gray-400 uppercase">My duties this week</p>
                <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                    @foreach ($this->upcoming as $upcomingDuty)
                        <li wire:key="duty-{{ $upcomingDuty->id }}" class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0">
                                <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $upcomingDuty->duty_date->isToday() ? 'Today' : $upcomingDuty->duty_date->format('D, M j') }} · {{ $upcomingDuty->shift->timeRange() }}</span>
                                <span class="block truncate text-xs text-gray-500">{{ $upcomingDuty->placeLabel() }}</span>
                            </span>
                            @if ($upcomingDuty->isRelief()) <x-ui.badge tone="purple">Relief</x-ui.badge> @endif
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-gray-400">Days not listed are days off. Operations sets the roster.</p>
            </div>
        @endif

        {{-- Punch modal. x-if: the camera only starts when opened and stops when closed. --}}
        <template x-if="punchOpen">
            <div class="ui-pop fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" x-bind:class="closing && 'ui-pop-closing'">
                <div class="ui-pop-panel relative w-full max-w-sm rounded-2xl bg-white p-4 shadow-xl dark:bg-gray-900" wire:ignore
                    x-data="clockCapture({ punchType: @js($this->nextPunch?->value), geocodeUrl: @js(route('attendance.reverse-geocode')), logoUrl: @js(asset('assets/OSSI-LOGO.png')), simulateAt: @js($simulateAt), maxAccuracy: @js((int) config('attendance.max_gps_accuracy_meters')) })">

                    <button type="button" x-on:click="$dispatch('close-punch-modal')" aria-label="Close" class="absolute top-3 right-3 z-10 rounded-full bg-black/40 p-1.5 text-white hover:bg-black/60">
                        <x-icon name="x" class="h-4 w-4" />
                    </button>

                    <div x-show="!photo" class="relative aspect-[3/4] w-full overflow-hidden rounded-xl bg-black">
                        {{-- Mirrored like a mirror for the selfie only; the back camera shows the scene as it is. --}}
                        <video x-ref="video" autoplay playsinline muted class="h-full w-full object-cover" x-bind:class="facing === 'user' && '-scale-x-100'"></video>

                        <button type="button" x-show="canSwitch && !cameraError" x-on:click="switchCamera()" x-bind:disabled="!cameraReady"
                            x-bind:aria-label="facing === 'user' ? 'Switch to the back camera' : 'Switch to the front camera'"
                            class="absolute top-3 left-3 z-10 flex items-center gap-1.5 rounded-full bg-black/40 py-1.5 pr-3 pl-2 text-xs font-medium text-white hover:bg-black/60 disabled:opacity-50">
                            <x-icon name="arrow-path" class="h-4 w-4" />
                            <span x-text="facing === 'user' ? 'Back camera' : 'Front camera'"></span>
                        </button>

                        <div x-show="cameraError" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/85 px-4 text-center">
                            <p class="text-sm font-medium text-white" x-text="cameraError"></p>
                            <button type="button" x-on:click="startCamera()" class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-800">Retry camera</button>
                        </div>

                        <div class="absolute inset-x-0 bottom-0 space-y-0.5 bg-black/55 px-3 py-2 text-left text-white">
                            {{-- Only an address looked up for where the phone is now (a moved fix shows "Looking up…"). --}}
                            <p x-text="currentAddress || locationError || (coords ? 'Looking up the street address…' : 'Waiting for GPS…')" class="line-clamp-2 text-sm leading-snug font-semibold"></p>
                            <p x-text="coordsText" class="text-xs text-gray-300"></p>
                            <p x-text="clockText" class="text-xs text-gray-300"></p>
                        </div>
                    </div>

                    <img x-show="photo" x-bind:src="photo" class="aspect-[3/4] w-full rounded-xl object-cover" alt="Captured photo">

                    <p x-show="message" x-text="message" role="alert" class="mt-3 rounded-md bg-red-50 p-2.5 text-sm text-red-800"></p>

                    {{-- Just-in-time notice (RA 10173): restated at the moment the camera and GPS fire. --}}
                    <p class="mt-3 text-center text-xs leading-relaxed text-gray-400">
                        This punch records your photo, location and the time, to verify attendance and compute your pay.
                        <a href="{{ route('privacy-notice') }}" target="_blank" rel="noopener" class="underline">Privacy notice</a>
                    </p>

                    <div class="mt-4 flex gap-3">
                        {{-- Locked until the camera AND the GPS fix are ready; the tooltip says which one is missing. --}}
                        <x-ui.tooltip class="flex-1" when="!canCapture" x-show="!photo">
                            <button type="button" x-bind:disabled="!canCapture" x-on:click="capture()"
                                class="w-full rounded-lg bg-gray-900 py-2.5 font-medium text-white disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-gray-800">
                                <span x-text="capturing ? 'Getting the address…' : 'Capture'">Capture</span>
                            </button>
                            <x-slot:content ><span x-text="captureBlockedReason"></span></x-slot:content>
                        </x-ui.tooltip>
                        <button type="button" x-show="photo" x-on:click="retake()" class="flex-1 rounded-lg bg-gray-200 py-2.5 font-medium text-gray-800">Retake</button>
                        <button type="button" x-show="photo" x-on:click="submit()" x-bind:disabled="submitting"
                            class="flex-1 rounded-lg bg-blue-600 py-2.5 font-medium text-white disabled:opacity-40">
                            <span x-show="!submitting" x-text="punchType === 'clock_in' ? 'Confirm clock in' : 'Confirm clock out'"></span>
                            <span x-show="submitting">Saving…</span>
                        </button>
                    </div>

                    @if (app()->isLocal())
                        <button type="button" x-show="!photo" x-on:click="simulate()"
                            class="mt-2 w-full rounded-lg border border-dashed border-amber-400 bg-amber-50 py-2 text-xs font-medium text-amber-700 dark:text-amber-400">
                            Dev only: simulate capture (no camera/GPS)
                        </button>
                    @endif

                    <canvas x-ref="canvas" class="hidden"></canvas>
                </div>
            </div>
        </template>
    </div>

    <div class="mt-10 min-w-0 lg:mt-0 lg:flex-1" x-data="{ tab: 'history' }">
        <div class="mb-4 inline-flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-900" role="tablist" aria-label="Attendance view">
            @foreach (['history' => 'Attendance History', 'punches' => 'Punch Log'] as $value => $label)
                <button type="button" role="tab" x-on:click="tab = '{{ $value }}'" x-bind:aria-selected="(tab === '{{ $value }}').toString()"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                    x-bind:class="tab === '{{ $value }}' ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-800' : 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100'">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div x-show="tab === 'history'"><livewire:attendance.history /></div>
        <div x-show="tab === 'punches'" x-cloak><livewire:attendance.punch-log /></div>
    </div>
</div>
