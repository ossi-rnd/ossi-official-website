{{-- One row per shift: clock-in and clock-out side by side, under a heading per day; cards on a phone.
     "Details" opens both selfies and both GPS positions. Colour only for warnings (late, left early,
     not clocked out). --}}
<div x-data="{ open: false, photo: null, caption: '' }" x-on:keydown.escape.window="open = false">
    <x-ui.card title="Attendance Log" description="Each shift on one row: when they clocked in and out, with the selfie and GPS position of each.">
        <x-slot:actions>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search employee…" aria-label="Search employee" class="ui-field mt-0! sm:w-48">
            <input type="date" wire:model.live="dateFrom" aria-label="From date" class="ui-field mt-0!">
            <input type="date" wire:model.live="dateTo" aria-label="To date" class="ui-field mt-0!">
        </x-slot:actions>

        @php
            $open = $this->open;
            $views = [
                'all' => ['All', null],
                'on-duty' => ['On duty now', count($open['onDuty'])],
                'late' => ['Late', null],
                'not-clocked-out' => ['Not clocked out', count($open['notClockedOut'])],
                'other-phone' => ['Other phone', $this->otherPhoneCount],
            ];

            // Each shift once, with what both layouts show; a new day starts a heading.
            $rows = [];
            $day = null;
            foreach ($this->logs as $log) {
                $rows[] = [
                    'log' => $log,
                    'employee' => $log->user,
                    'in' => $log->punches->first(fn ($punch) => $punch->type === \App\Enums\PunchType::ClockIn),
                    'out' => $log->punches->first(fn ($punch) => $punch->type === \App\Enums\PunchType::ClockOut),
                    'notClockedOut' => in_array($log->id, $open['notClockedOut'], true),
                    'worked' => $log->workedMinutes(),
                    'heading' => $day === null || ! $log->log_date->isSameDay($day) ? $this->dayHeading($log->log_date) : null,
                ];
                $day = $log->log_date;
            }
        @endphp

        <div class="relative" x-data="{ shown: @js($show) }">
            <div class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-gray-200 px-4 dark:border-gray-800" role="tablist" aria-label="Which shifts">
                @foreach ($views as $value => [$label, $count])
                    <button type="button" role="tab" wire:click="showOnly('{{ $value }}')" x-on:click="shown = '{{ $value }}'"
                        aria-selected="{{ $show === $value ? 'true' : 'false' }}" x-bind:aria-selected="(shown === '{{ $value }}').toString()"
                        class="-mb-px inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors"
                        x-bind:class="{ 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400': shown === '{{ $value }}', 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300': shown !== '{{ $value }}' }">
                        {{ $label }}
                        @if ($count !== null)
                            <span class="rounded-full bg-gray-100 px-1.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $count }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
            <div wire:loading.delay.shortest wire:target="showOnly" class="absolute inset-x-0 bottom-0 h-0.5 overflow-hidden" aria-hidden="true">
                <div class="tab-loading-bar h-full w-1/3 bg-blue-500"></div>
            </div>
        </div>

        @include('livewire.admin.partials.area-filter')

        {{-- Laptop and up: a table. --}}
        <div class="hidden overflow-x-auto sm:block">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th scope="col" class="ui-th">Employee</th>
                        <th scope="col" class="ui-th">Clock in</th>
                        <th scope="col" class="ui-th">Clock out</th>
                        <th scope="col" class="ui-th">Hours</th>
                        <th scope="col" class="ui-th">Status</th>
                        <th scope="col" class="ui-th"><span class="sr-only">Details</span></th>
                    </tr>
                </thead>

                @forelse ($rows as ['log' => $log, 'employee' => $employee, 'in' => $in, 'out' => $out, 'notClockedOut' => $notClockedOut, 'worked' => $worked, 'heading' => $heading])
                    {{-- A heading row per day: its own row group, read out as a heading. --}}
                    @if ($heading)
                        <tbody wire:key="day-{{ $log->log_date->toDateString() }}">
                            <tr>
                                <th scope="rowgroup" colspan="6" class="border-y border-gray-200 bg-gray-50 px-4 py-2 text-left text-xs font-semibold tracking-wide text-gray-600 uppercase dark:border-gray-800 dark:bg-gray-800/60 dark:text-gray-300">{{ $heading }}</th>
                            </tr>
                        </tbody>
                    @endif

                    <tbody x-data="{ details: false }" wire:key="log-{{ $log->id }}">
                        <tr>
                            <td class="ui-td">
                                <p><x-person-link :user="$employee" /></p>
                                <p class="text-xs text-gray-500">{{ $employee->employeeProfile?->assignmentLabel() ?? '—' }}</p>
                            </td>

                            @foreach ([[$in, $log->clock_in_at, 'Clock in'], [$out, $log->clock_out_at, 'Clock out']] as [$punch, $at, $what])
                                <td class="ui-td">
                                    <div class="flex items-center gap-2.5">
                                        @if ($punch)
                                            <button type="button" x-on:click="open = true; photo = @js($punch->photoUrl()); caption = @js($employee->name.' · '.$what.' · '.$punch->punched_at->format('M j, Y g:i A'))"
                                                class="block h-10 w-8 shrink-0 overflow-hidden rounded-md border border-gray-200 bg-gray-100 hover:ring-2 hover:ring-blue-400 dark:border-gray-700 dark:bg-gray-800 dark:hover:ring-blue-500" aria-label="{{ $what }} selfie of {{ $employee->name }}">
                                                <img src="{{ $punch->photoUrl() }}" alt="" class="h-full w-full object-cover" loading="lazy">
                                            </button>
                                        @endif
                                        <div class="min-w-0">
                                            @if ($at)
                                                <p class="font-medium whitespace-nowrap text-gray-800 tabular-nums dark:text-gray-100">{{ $at->format('g:i A') }}@unless ($at->isSameDay($log->log_date))<span class="font-normal text-gray-500"> · {{ $at->format('M j') }}</span>@endunless</p>
                                            @else
                                                <p class="text-gray-400">—</p>
                                            @endif

                                            @if ($what === 'Clock in' && $log->late_minutes > 0)
                                                <p class="text-xs whitespace-nowrap text-amber-700 dark:text-amber-400">{{ \App\Support\Duration::minutes($log->late_minutes) }} late</p>
                                            @elseif ($what === 'Clock out' && $log->undertime_minutes > 0)
                                                <p class="text-xs whitespace-nowrap text-amber-700 dark:text-amber-400">Left {{ \App\Support\Duration::minutes($log->undertime_minutes) }} early</p>
                                            @elseif ($what === 'Clock out' && $log->overtime_minutes > 0)
                                                <p class="text-xs whitespace-nowrap text-gray-500">{{ \App\Support\Duration::minutes($log->overtime_minutes) }} overtime</p>
                                            @elseif ($punch?->address)
                                                <p class="max-w-[13rem] truncate text-xs text-gray-500" title="{{ $punch->address }}">{{ $punch->address }}</p>
                                            @endif
                                            @if ($punch?->other_device)
                                                <x-ui.badge tone="amber" class="mt-0.5">Other phone</x-ui.badge>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            @endforeach

                            <td class="ui-td whitespace-nowrap tabular-nums">{{ $worked !== null ? \App\Support\Duration::minutes($worked) : '—' }}</td>
                            <td class="ui-td">@include('livewire.admin.partials.attendance-log-status')</td>
                            <td class="ui-td text-right">
                                <button type="button" x-on:click="details = ! details" x-bind:aria-expanded="details.toString()" aria-controls="log-details-{{ $log->id }}"
                                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-800 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-gray-100">
                                    Details
                                    <x-icon name="chevron-down" class="h-3.5 w-3.5 transition-transform" x-bind:class="details && 'rotate-180'" />
                                </button>
                            </td>
                        </tr>
                        <tr x-show="details" x-cloak id="log-details-{{ $log->id }}">
                            <td colspan="6" class="border-b border-gray-100 bg-gray-50/60 px-4 py-4 dark:border-gray-800 dark:bg-gray-800/30">
                                @include('livewire.admin.partials.attendance-log-details')
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="6" class="ui-td py-8 text-center">No shifts match your filters.</td></tr>
                    </tbody>
                @endforelse
            </table>
        </div>

        {{-- Phone: one card per shift, in and out side by side. --}}
        <div class="sm:hidden">
            @forelse ($rows as ['log' => $log, 'employee' => $employee, 'in' => $in, 'out' => $out, 'notClockedOut' => $notClockedOut, 'worked' => $worked, 'heading' => $heading])
                @if ($heading)
                    <h3 wire:key="m-day-{{ $log->log_date->toDateString() }}" class="border-y border-gray-200 bg-gray-50 px-4 py-2 text-xs font-semibold tracking-wide text-gray-600 uppercase dark:border-gray-800 dark:bg-gray-800/60 dark:text-gray-300">{{ $heading }}</h3>
                @endif
                <div x-data="{ details: false }" wire:key="m-log-{{ $log->id }}" class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate"><x-person-link :user="$employee" /></p>
                            <p class="truncate text-xs text-gray-500">{{ $employee->employeeProfile?->assignmentLabel() ?? '—' }}</p>
                        </div>
                        <div class="shrink-0 text-right">@include('livewire.admin.partials.attendance-log-status')</div>
                    </div>
                    <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <dt class="text-xs text-gray-400">In</dt>
                            <dd class="font-medium text-gray-800 tabular-nums dark:text-gray-100">{{ $log->clock_in_at?->format('g:i A') ?? '—' }}</dd>
                            @if ($log->late_minutes > 0)<dd class="text-xs text-amber-700 dark:text-amber-400">{{ \App\Support\Duration::minutes($log->late_minutes) }} late</dd>@endif
                            @if ($in?->other_device)<dd><x-ui.badge tone="amber">Other phone</x-ui.badge></dd>@endif
                        </div>
                        <div>
                            <dt class="text-xs text-gray-400">Out</dt>
                            <dd class="font-medium text-gray-800 tabular-nums dark:text-gray-100">{{ $log->clock_out_at?->format('g:i A') ?? '—' }}</dd>
                            @if ($log->undertime_minutes > 0)<dd class="text-xs text-amber-700 dark:text-amber-400">Left {{ \App\Support\Duration::minutes($log->undertime_minutes) }} early</dd>@endif
                            @if ($out?->other_device)<dd><x-ui.badge tone="amber">Other phone</x-ui.badge></dd>@endif
                        </div>
                        <div>
                            <dt class="text-xs text-gray-400">Hours</dt>
                            <dd class="font-medium text-gray-800 tabular-nums dark:text-gray-100">{{ $worked !== null ? \App\Support\Duration::minutes($worked) : '—' }}</dd>
                        </div>
                    </dl>
                    <button type="button" x-on:click="details = ! details" x-bind:aria-expanded="details.toString()" aria-controls="m-log-details-{{ $log->id }}"
                        class="mt-2 inline-flex items-center gap-1 rounded-md py-1 text-xs font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100">
                        Selfies and location
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 transition-transform" x-bind:class="details && 'rotate-180'" />
                    </button>
                    <div x-show="details" x-cloak id="m-log-details-{{ $log->id }}" class="mt-2">
                        @include('livewire.admin.partials.attendance-log-details')
                    </div>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-gray-500">No shifts match your filters.</p>
            @endforelse
        </div>

        <x-slot:footer>{{ $this->logs->links() }}</x-slot:footer>
    </x-ui.card>

    {{-- The selfie, big. Closes only with ✕ or Esc: a stray tap outside no longer shuts it. --}}
    <div x-show="open" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-label="Selfie" class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-8 backdrop-blur-sm">
        <figure class="relative">
            <button type="button" x-on:click="open = false" aria-label="Close" class="absolute -top-11 right-0 rounded-full bg-white/10 p-2 text-white transition-colors hover:bg-white/25 focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none"><x-icon name="x" class="h-5 w-5" /></button>
            <img x-bind:src="photo" x-bind:alt="caption" class="max-h-[75vh] max-w-[80vw] rounded-2xl object-contain shadow-2xl">
            <figcaption class="mt-2 text-center text-sm text-gray-200" x-text="caption"></figcaption>
        </figure>
    </div>
</div>
