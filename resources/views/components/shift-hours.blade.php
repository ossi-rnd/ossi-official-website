@props(['start', 'end', 'grace'])

{{--
    Typed shift hours: common hours in one tap, Start / End, what was typed in words (the length,
    "ends the next day"), and the grace before late. $start / $end / $grace name the Livewire
    properties (e.g. "form.custom_start"); saving turns them into a Shift with ShiftForTimes,
    reusing one with exactly these times. Used by HR's employee form and the Duty Roster.
--}}
{{-- Alpine for the conveniences (presets, the live summary); the values are still Livewire's. --}}
<div {{ $attributes->class('space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800/50') }}
    x-data="{
        start: $wire.entangle(@js($start)),
        end: $wire.entangle(@js($end)),
        grace: $wire.entangle(@js($grace)),
        minutes(time) { const [h, m] = (time || '').split(':').map(Number); return h * 60 + m; },
        clock(time) { const [h, m] = time.split(':').map(Number); return ((h + 11) % 12 + 1) + ':' + String(m).padStart(2, '0') + (h < 12 ? ' AM' : ' PM'); },
        get ready() { return /^\d{2}:\d{2}$/.test(this.start || '') && /^\d{2}:\d{2}$/.test(this.end || '') && this.start !== this.end; },
        get overnight() { return this.ready && this.minutes(this.end) <= this.minutes(this.start); },
        get length() {
            const total = (this.minutes(this.end) - this.minutes(this.start) + 1440) % 1440;
            const h = Math.floor(total / 60), m = total % 60;
            return (h ? h + (h === 1 ? ' hour' : ' hours') : '') + (m ? ' ' + m + ' min' : '');
        },
    }">
    {{-- Common hours, one tap. --}}
    <div>
        <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">Common hours</p>
        <div class="flex flex-wrap gap-1.5">
            @foreach ([['06:00', '15:00', '6–3'], ['07:00', '16:00', '7–4'], ['08:00', '17:00', '8–5'], ['09:00', '18:00', '9–6'], ['22:00', '07:00', '10 PM–7 AM']] as [$from, $to, $label])
                <button type="button" x-on:click="start = @js($from); end = @js($to)"
                    x-bind:class="start === @js($from) && end === @js($to) ? 'border-blue-600 bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:hover:bg-blue-500/20' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800'"
                    class="rounded-full border px-2.5 py-1 text-xs font-medium">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 gap-2">
        <x-ui.input label="Start" type="time" :name="$start" x-model="start" />
        <x-ui.input label="End" type="time" :name="$end" x-model="end" />
    </div>

    {{-- What was typed, in words: the length, and whether it runs past midnight. --}}
    <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs" x-show="ready" x-cloak>
        <x-icon name="clock" class="h-3.5 w-3.5 text-gray-400" />
        <span class="text-gray-700 dark:text-gray-200"><span class="font-medium" x-text="length"></span> · <span x-text="clock(start)"></span> → <span x-text="clock(end)"></span></span>
        <span x-show="overnight" class="rounded-full bg-amber-100 px-1.5 whitespace-nowrap text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">ends the next day</span>
    </p>

    <div>
        <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">Grace before late <span class="font-normal">(minutes)</span></p>
        <div class="inline-flex flex-wrap rounded-lg border border-gray-300 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-900" role="radiogroup" aria-label="Grace minutes">
            @foreach ([0, 5, 10, 15, 30] as $minutes)
                <button type="button" role="radio" x-on:click="grace = '{{ $minutes }}'" x-bind:aria-checked="(String(grace) === '{{ $minutes }}').toString()"
                    x-bind:class="String(grace) === '{{ $minutes }}' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100'"
                    class="rounded-md px-2.5 py-1 text-xs font-medium whitespace-nowrap tabular-nums">{{ $minutes === 0 ? 'None' : $minutes }}</button>
            @endforeach
        </div>
        @error($grace) <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>
