{{-- Your shifts, one row each under a heading per day: the selfie, time and place of each clock-in
     and clock-out. Hours and status are on Attendance History. Cards on a phone. --}}
<x-ui.card title="Punch Log" description="The photo, place and time recorded when you clocked in and out, one row per shift."
    x-data="{ open: false, photo: null, caption: '' }" x-on:keydown.escape.window="open = false">
    @php
        // Each shift once, with what both layouts show; a new day starts a heading.
        $rows = [];
        $day = null;
        foreach ($this->logs as $log) {
            $rows[] = [
                'log' => $log,
                'in' => $log->punches->first(fn ($punch) => $punch->type === \App\Enums\PunchType::ClockIn),
                'out' => $log->punches->first(fn ($punch) => $punch->type === \App\Enums\PunchType::ClockOut),
                'heading' => $day === null || ! $log->log_date->isSameDay($day) ? $this->dayHeading($log->log_date) : null,
            ];
            $day = $log->log_date;
        }
    @endphp

    {{-- Laptop and up: a table. --}}
    <div class="hidden overflow-x-auto sm:block">
        <table class="ui-table">
            <caption class="sr-only">Your shifts with the photo, place and time of each clock-in and clock-out</caption>
            <thead><tr><th scope="col" class="ui-th">Clock in</th><th scope="col" class="ui-th">Clock out</th></tr></thead>
            @forelse ($rows as ['log' => $log, 'in' => $in, 'out' => $out, 'heading' => $heading])
                @if ($heading)
                    <tbody wire:key="punch-day-{{ $log->log_date->toDateString() }}">
                        <tr>
                            <th scope="rowgroup" colspan="2" class="border-y border-gray-200 bg-gray-50 px-4 py-2 text-left text-xs font-semibold tracking-wide text-gray-600 uppercase dark:border-gray-800 dark:bg-gray-800/60 dark:text-gray-300">{{ $heading }}</th>
                        </tr>
                    </tbody>
                @endif
                <tbody wire:key="punch-log-{{ $log->id }}">
                    <tr>
                        @foreach ([[$in, $log->clock_in_at, 'Clock in'], [$out, $log->clock_out_at, 'Clock out']] as [$punch, $at, $what])
                            <td class="ui-td w-1/2 align-top">
                                @include('livewire.attendance.partials.punch-proof')
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            @empty
                <tbody><tr><td colspan="2" class="ui-td py-8 text-center">No punches yet.</td></tr></tbody>
            @endforelse
        </table>
    </div>

    {{-- Phone: one card per shift. --}}
    <div class="sm:hidden">
        @forelse ($rows as ['log' => $log, 'in' => $in, 'out' => $out, 'heading' => $heading])
            @if ($heading)
                <h3 wire:key="m-punch-day-{{ $log->log_date->toDateString() }}" class="border-y border-gray-200 bg-gray-50 px-4 py-2 text-xs font-semibold tracking-wide text-gray-600 uppercase dark:border-gray-800 dark:bg-gray-800/60 dark:text-gray-300">{{ $heading }}</h3>
            @endif
            <div wire:key="m-punch-log-{{ $log->id }}" class="space-y-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                @foreach ([[$in, $log->clock_in_at, 'Clock in'], [$out, $log->clock_out_at, 'Clock out']] as [$punch, $at, $what])
                    @include('livewire.attendance.partials.punch-proof')
                @endforeach
            </div>
        @empty
            <p class="px-4 py-8 text-center text-sm text-gray-500">No punches yet.</p>
        @endforelse
    </div>

    <x-slot:footer>{{ $this->logs->links() }}</x-slot:footer>

    {{-- The selfie, big. Closes only with ✕ or Esc: a stray tap outside no longer shuts it. --}}
    <div x-show="open" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-label="Selfie" class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-8 backdrop-blur-sm">
        <figure class="relative">
            <button type="button" x-on:click="open = false" aria-label="Close" class="absolute -top-11 right-0 rounded-full bg-white/10 p-2 text-white transition-colors hover:bg-white/25 focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none"><x-icon name="x" class="h-5 w-5" /></button>
            <img x-bind:src="photo" x-bind:alt="caption" class="max-h-[75vh] max-w-[80vw] rounded-2xl object-contain shadow-2xl">
            <figcaption class="mt-2 text-center text-sm text-gray-200" x-text="caption"></figcaption>
        </figure>
    </div>
</x-ui.card>
