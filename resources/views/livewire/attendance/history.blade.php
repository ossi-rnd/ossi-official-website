<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">My Attendance History</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($mode === 'recent') Last {{ $days }} days.
                @elseif ($mode === 'month') Full DTR for {{ \Carbon\CarbonImmutable::parse($month.'-01')->format('F Y') }}.
                @else Yearly rollup for {{ $year }}. Click a month for the daily detail.
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-900" role="group" aria-label="History view">
                @foreach (['recent' => 'Recent', 'month' => 'Monthly', 'year' => 'Yearly'] as $value => $label)
                    <button type="button" wire:click="setMode('{{ $value }}')" aria-pressed="{{ $mode === $value ? 'true' : 'false' }}"
                        @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-gray-900 text-white dark:bg-white dark:text-gray-800' => $mode === $value, 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100' => $mode !== $value])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($mode === 'recent')
                <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-800 dark:bg-gray-900" role="group" aria-label="Range">
                    @foreach ([7, 15, 30] as $value)
                        <button type="button" wire:click="setRange({{ $value }})" aria-pressed="{{ $days === $value ? 'true' : 'false' }}"
                            @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-gray-900 text-white dark:bg-white dark:text-gray-800' => $days === $value, 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100' => $days !== $value])>{{ $value }}D</button>
                    @endforeach
                </div>
            @elseif ($mode === 'month')
                <div class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-1 py-0.5 dark:border-gray-800 dark:bg-gray-900">
                    <button type="button" wire:click="previousMonth" aria-label="Previous month" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/10"><x-icon name="chevron-left" class="h-4 w-4" /></button>
                    <span class="min-w-28 px-1 text-center text-sm font-medium text-gray-700 dark:text-gray-200">{{ \Carbon\CarbonImmutable::parse($month.'-01')->format('F Y') }}</span>
                    @if ($month === today()->format('Y-m'))
                        <x-ui.tooltip text="This is the current month. Later months have no attendance yet." align="end"><button type="button" disabled aria-label="Next month" class="cursor-not-allowed rounded-md p-1.5 text-gray-500 opacity-30"><x-icon name="chevron-right" class="h-4 w-4" /></button></x-ui.tooltip>
                    @else
                        <button type="button" wire:click="nextMonth" aria-label="Next month" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/10"><x-icon name="chevron-right" class="h-4 w-4" /></button>
                    @endif
                </div>
            @else
                <div class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-1 py-0.5 dark:border-gray-800 dark:bg-gray-900">
                    <button type="button" wire:click="previousYear" aria-label="Previous year" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/10"><x-icon name="chevron-left" class="h-4 w-4" /></button>
                    <span class="min-w-14 px-1 text-center text-sm font-medium text-gray-700 dark:text-gray-200">{{ $year }}</span>
                    @if ($year >= today()->year)
                        <x-ui.tooltip text="This is the current year. Later years have no attendance yet." align="end"><button type="button" disabled aria-label="Next year" class="cursor-not-allowed rounded-md p-1.5 text-gray-500 opacity-30"><x-icon name="chevron-right" class="h-4 w-4" /></button></x-ui.tooltip>
                    @else
                        <button type="button" wire:click="nextYear" aria-label="Next year" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/10"><x-icon name="chevron-right" class="h-4 w-4" /></button>
                    @endif
                </div>
            @endif

            @if ($mode !== 'year')
                <x-ui.button variant="secondary" size="sm" type="button" wire:click="exportCsv"><x-icon name="download" class="h-4 w-4" /> Export CSV</x-ui.button>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        <x-ui.stat label="Present" :value="$this->summary['present'].' / '.$this->daysInRange" />
        <x-ui.stat label="On time" :value="$this->summary['onTime']" tone="green" />
        <x-ui.stat label="Late" :value="$this->summary['late']" tone="amber" />
        <x-ui.stat label="Absent" :value="$this->summary['absent']" tone="red" />
        <x-ui.stat label="Total hours" :value="$this->summary['totalHours'].'h'" />
    </div>

    <div class="ui-card mt-3 p-3">
        <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
            <span>Punctuality rate</span>
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ $this->summary['punctualityRate'] }}%</span>
        </div>
        <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" role="progressbar" aria-label="Punctuality rate" aria-valuenow="{{ $this->summary['punctualityRate'] }}" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-green-500" style="width: {{ $this->summary['punctualityRate'] }}%"></div>
        </div>
    </div>

    @if ($mode === 'year')
        <div class="ui-card mt-4 p-4">
            <p class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Hours worked per month</p>
            <div class="h-64">
                <canvas role="img" aria-label="Hours worked per month in {{ $year }}" wire:key="yearly-{{ $year }}" wire:ignore x-data="attendanceYearlyChart(@js($this->yearly))"></canvas>
            </div>
        </div>

        <div class="ui-card mt-4 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Month</th><th class="ui-th">Present</th><th class="ui-th">Late</th><th class="ui-th">Absent</th><th class="ui-th">Hours</th><th class="ui-th">Punctuality</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse (array_reverse($this->yearly) as $row)
                            <tr wire:key="month-{{ $row['ym'] }}">
                                <td class="ui-td font-medium text-gray-800 dark:text-gray-100">{{ $row['label'] }}</td>
                                <td class="ui-td">{{ $row['present'] }}</td>
                                <td class="ui-td">{{ $row['late'] }}</td>
                                <td class="ui-td">{{ $row['absent'] }}</td>
                                <td class="ui-td">{{ $row['totalHours'] }}h</td>
                                <td class="ui-td">{{ $row['punctualityRate'] }}%</td>
                                <td class="ui-td text-right"><button type="button" wire:click="viewMonth('{{ $row['ym'] }}')" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">View</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="ui-td py-8 text-center">No attendance in {{ $year }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="ui-card mt-4 p-4">
            <p class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Hours worked</p>
            <div class="h-64">
                <canvas role="img" aria-label="Hours worked per day, coloured by status, with a dashed {{ $this->shiftHours }}-hour shift target" wire:key="daily-{{ $mode }}-{{ $days }}-{{ $month }}" wire:ignore
                    x-data="attendanceHistoryChart(@js($this->history), @js($this->shiftHours))"></canvas>
            </div>
            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-green-600"></span>On time</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-600"></span>Late</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-orange-500"></span>Undertime</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-red-600"></span>Late &amp; undertime</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-0 w-3 border-t-2 border-dashed border-indigo-500"></span>Shift target</span>
            </div>
        </div>

        <div class="ui-card mt-4 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <caption class="sr-only">Daily attendance</caption>
                    <thead><tr><th class="ui-th">Date</th><th class="ui-th">Time in</th><th class="ui-th">Time out</th><th class="ui-th">Hours</th><th class="ui-th">Status</th></tr></thead>
                    <tbody>
                        @foreach (array_reverse($this->history) as $day)
                            @continue($day['status'] === 'upcoming')
                            <tr wire:key="day-{{ $day['date'] }}" @class(['bg-blue-50/40 dark:bg-blue-500/5' => $day['isToday']])>
                                <td class="ui-td text-gray-800 dark:text-gray-100">{{ $day['label'] }}{{ $day['isToday'] ? ' (Today)' : '' }}</td>
                                <td class="ui-td">{{ $day['clockIn'] ?? '—' }}</td>
                                <td class="ui-td">{{ $day['clockOut'] ?? '—' }}</td>
                                <td class="ui-td">{{ $day['hours'] !== null ? $day['hours'].'h' : '—' }}</td>
                                <td class="ui-td"><x-ui.status-badge :status="$day['status']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
