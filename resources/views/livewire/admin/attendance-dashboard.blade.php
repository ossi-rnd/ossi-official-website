@php
    $user = auth()->user();
    $showAttendance = $this->canSeeAttendance();
    $areaKey = $this->areaRegion.'-'.$this->areaProvince.'-'.$this->areaClient;
    // "Waiting for you" and "At a glance" share a row on wide screens: each usually has
    // two to four cards, which alone left half the page empty. Alone, one takes the row.
    $paired = $this->highlights !== [];
    // Many of both (a Super Admin sees every job): side by side, each tile would be too narrow
    // to read, so they stack, each the full width (and wrapping at 6 a row).
    $sideBySide = $paired && count($this->waiting) + count($this->highlights) <= 8;
    // The row is split by how many numbers each side has (at least 2 each), so they come out about
    // the same width: HR's 2 + 3 get 2fr / 3fr instead of 50/50, where three were cut short.
    $split = max(2, count($this->waiting)).'fr '.max(2, count($this->highlights)).'fr';
    // Today's lines: "16% of 31", against the headcount in the chosen area.
    $total = $this->stats['total'];
    // Shown as two parts: "39%" in the number's colour, then "of 31" in gray.
    $pct = fn (int $n): int => $total > 0 ? (int) round($n / $total * 100) : 0;
    $share = fn (int $n): ?string => $total > 0 ? $pct($n).'%' : null;
    $of = $total > 0 ? 'of '.$total : null;
@endphp

<div class="space-y-6">
    {{-- Who and when. --}}
    <div>
        <p class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $this->greeting() }}, {{ str($user?->name)->before(' ') }}</p>
        {{-- The roles beside the date: what this page is showing them, read with the name. --}}
        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
            {{ now()->format('l, F j, Y') }}
            @foreach ($user?->roleList() ?? [] as $role)
                <x-ui.badge :tone="$role->tone()">{{ $role->label() }}</x-ui.badge>
            @endforeach
        </p>
    </div>

    {{-- Each section is one card of numbers split by thin lines (x-ui.metric-strip). Both stretch
         to the taller one, so the row has one height. Blue lines are "needs you", gray "for your information". --}}
    <div @class(['grid grid-cols-1 gap-6', 'lg:[grid-template-columns:var(--dash-split)]' => $sideBySide]) style="--dash-split: {{ $split }}">
        <section aria-labelledby="waiting-heading" class="flex flex-col gap-3">
            <h2 id="waiting-heading" class="text-sm font-semibold text-gray-800 dark:text-gray-100">Waiting for you</h2>
            @if ($this->waiting === [])
                {{-- Empty: a calm "done" state, the same height as the card beside it. --}}
                <div class="ui-card flex flex-1 flex-col items-center justify-center px-6 py-8 text-center">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-green-50 dark:bg-green-500/10">
                        <x-ui.verdict-dot tone="green" size="md" label="" />
                    </span>
                    <p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100">You're all caught up</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Requests and alerts that need you will show up here.</p>
                </div>
            @else
                <x-ui.metric-strip :count="count($this->waiting)" class="flex-1">
                    @foreach ($this->waiting as $item)
                        {{-- No dot: every card here needs you (the heading says so), so a dot on each told nothing.
                             Dots are kept for warnings (amber / red) elsewhere. --}}
                        <x-ui.metric wire:key="waiting-{{ $item['route'] }}" :href="route($item['route'])" :icon="$item['icon']" :label="$item['label']" :value="$item['badge']" :hint="$item['hint']" />
                    @endforeach
                </x-ui.metric-strip>
            @endif
        </section>

        {{-- A few numbers per job (HR, Accounting, Operations, Admin, the President or Vice President). --}}
        @if ($paired)
            @php
                // The job ("People", "Payroll") only matters to someone with two of them.
                $mixed = collect($this->highlights)->pluck('group')->unique()->count() > 1;
            @endphp
            <section aria-labelledby="glance-heading" class="flex flex-col gap-3">
                <h2 id="glance-heading" class="text-sm font-semibold text-gray-800 dark:text-gray-100">At a glance</h2>
                <x-ui.metric-strip :count="count($this->highlights)" class="flex-1">
                    @foreach ($this->highlights as $tile)
                        <x-ui.metric wire:key="tile-{{ $tile['label'] }}" :href="$tile['url']" :icon="$tile['icon']" :group="$mixed ? $tile['group'] : null" :label="$tile['label']" :value="$tile['value']" :hint="$tile['hint']" :tone="$tile['tone']" />
                    @endforeach
                </x-ui.metric-strip>
            </section>
        @endif
    </div>

    {{-- Today's attendance, for the chosen area. --}}
    @if ($showAttendance)
        <section aria-labelledby="today-heading" class="space-y-3">
            {{-- On a phone the filter goes under the heading, full width, instead of squeezing beside it. --}}
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="today-heading" class="text-sm font-semibold text-gray-800 dark:text-gray-100">Today</h2>
                @include('livewire.admin.partials.area-filter', ['bare' => true])
            </div>

            {{-- Clicking one filters today's DTR below to it (clicking it again shows everyone). --}}
            @php
                $pick = fn (string $status): string => "\$set('statusFilter', '".($this->statusFilter === $status ? 'all' : $status)."')";
            @endphp
            <x-ui.metric-strip :count="6">
                {{-- "Headcount", not "On payroll": a count of people, read by HR and Operations too. --}}
                <x-ui.metric icon="users" label="Headcount" :value="$total" :hint="$this->scopeLabel()" :progress="$total > 0 ? 100 : 0" wire:click="{{ $pick('all') }}" :active="false" />
                <x-ui.metric icon="check" label="On time" :value="$this->stats['on_time']" :hint="$share($this->stats['on_time'])" :context="$of" :progress="$pct($this->stats['on_time'])" tone="green" wire:click="{{ $pick('on_time') }}" :active="$statusFilter === 'on_time'" />
                <x-ui.metric icon="clock" label="Late" :value="$this->stats['late']" :hint="$share($this->stats['late'])" :context="$of" :progress="$pct($this->stats['late'])" tone="amber" wire:click="{{ $pick('late') }}" :active="$statusFilter === 'late'" />
                <x-ui.metric icon="clock-alert" label="Not in yet" :value="$this->stats['absent']" :hint="$share($this->stats['absent'])" :context="$of" :progress="$pct($this->stats['absent'])" tone="red" wire:click="{{ $pick('absent') }}" :active="$statusFilter === 'absent'" />
                <x-ui.metric icon="login" label="Still clocked in" :value="$this->stats['clocked_in']" :hint="$share($this->stats['clocked_in'])" :context="$of" :progress="$pct($this->stats['clocked_in'])" tone="blue" wire:click="{{ $pick('clocked_in') }}" :active="$statusFilter === 'clocked_in'" />
                <x-ui.metric icon="calendar" label="On leave" :value="$this->stats['on_leave']" :hint="$share($this->stats['on_leave'])" :context="$of" :progress="$pct($this->stats['on_leave'])" wire:click="{{ $pick('on_leave') }}" :active="$statusFilter === 'on_leave'" />
            </x-ui.metric-strip>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {{-- Attendance over time, laid out like a finance chart: the headline number and
                     its change first, the range tabs beside it, then one clean line. --}}
                @php
                    $headline = $this->trendHeadline;
                    $change = $headline['change'];
                @endphp
                <div class="ui-card p-5 lg:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Attendance <span class="text-gray-400 dark:text-gray-500">· {{ $this->viewerScope() }}</span></p>
                            <p class="mt-1 flex items-baseline gap-2">
                                <span class="text-3xl leading-none font-semibold tracking-tight text-gray-800 tabular-nums dark:text-gray-100">{{ $headline['rate'] !== null ? $headline['rate'].'%' : '—' }}</span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">in today</span>
                            </p>
                            <p class="mt-1.5 text-sm tabular-nums">
                                @if ($change === null)
                                    <span class="text-gray-500 dark:text-gray-400">No comparison yet</span>
                                @elseif ($change === 0)
                                    <span class="font-medium text-gray-600 dark:text-gray-300">Same as yesterday</span>
                                @else
                                    <span @class(['font-medium', 'text-green-600 dark:text-green-400' => $change > 0, 'text-red-600 dark:text-red-400' => $change < 0])>
                                        {{ $change > 0 ? '+' : '−' }}{{ abs($change) }} pts {{ $change > 0 ? '↑' : '↓' }}
                                    </span>
                                    <span class="text-gray-500 dark:text-gray-400">vs yesterday</span>
                                @endif
                                <span class="text-gray-400 dark:text-gray-500">· {{ $headline['in'] }} of {{ $headline['expected'] }} expected</span>
                            </p>
                        </div>
                        {{-- Range tabs, underlined like a finance chart's 1D / 5D / 1M. --}}
                        <div class="flex gap-1 border-b border-gray-200 dark:border-gray-800" role="group" aria-label="Range">
                            @foreach (\App\Livewire\Admin\AttendanceDashboard::TREND_RANGES as $days)
                                <button type="button" wire:click="setTrendDays({{ $days }})" aria-pressed="{{ $trendDays === $days ? 'true' : 'false' }}"
                                    @class(['-mb-px border-b-2 px-3 pb-2 text-sm font-medium transition-colors', 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' => $trendDays === $days, 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white' => $trendDays !== $days])>{{ $days }}D</button>
                            @endforeach
                        </div>
                    </div>
                    {{-- Keyed by the range: the chart is drawn once, so a new range draws a new one. --}}
                    <div class="mt-5 h-60" wire:key="trend-chart-{{ $trendDays }}">
                        <canvas role="img" aria-label="Share of people in, each day for the last {{ $trendDays }} days" wire:ignore x-data="attendanceTrendChart(@js($this->trend))"></canvas>
                    </div>
                </div>

                @php $inToday = $this->stats['on_time'] + $this->stats['late']; @endphp
                <div class="ui-card p-5">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Today <span class="text-gray-400 dark:text-gray-500">· {{ $this->scopeLabel() }}</span></p>
                    <p class="mt-1 flex items-baseline gap-2">
                        <span class="text-3xl leading-none font-semibold tracking-tight text-gray-800 tabular-nums dark:text-gray-100">{{ $inToday }}</span>
                        {{-- "Expected" leaves out people on leave, as the attendance headline does. --}}
                        <span class="text-sm text-gray-500 dark:text-gray-400">in · of {{ $this->stats['total'] - $this->stats['on_leave'] }} expected</span>
                    </p>
                    {{-- Keyed by the area: the chart is drawn once, so a new area draws a new one. --}}
                    <div class="mt-5 h-60" wire:key="today-chart-{{ $areaKey }}"><canvas role="img" aria-label="Today's breakdown: on time, late, not in yet, on leave" wire:ignore x-data="todayBreakdownChart(@js($this->stats))"></canvas></div>
                </div>
            </div>
        </section>
    @endif

    {{-- Payroll (Accounting): not the Payroll page's charts again, but a summary and what's
         left to do: the latest totals in one strip, and each open run's progress. --}}
    @if ($this->canSeePayroll() && $this->payrollRuns !== [])
        @php
            $payroll = $this->payroll;
            $peso = fn (float $amount): string => '₱'.number_format($amount, 2);
            $payChange = $payroll['change_pct'];
            $steps = \App\Enums\PayrollRunStatus::cases();
        @endphp
        <section aria-labelledby="payroll-heading" class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <h2 id="payroll-heading" class="text-sm font-semibold text-gray-800 dark:text-gray-100">Payroll</h2>
                <a href="{{ route('payroll') }}" wire:navigate class="text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Charts and details on Payroll</a>
            </div>

            @if ($payroll['latest_label'] !== null)
                <x-ui.metric-strip :count="3">
                    <x-ui.metric icon="banknotes" label="Net pay" :value="$peso($payroll['latest_net'])" :hint="$payroll['latest_label']" :href="route('payroll')" />
                    <x-ui.metric icon="document-plus" label="Deductions" :value="$peso($payroll['latest_deductions'])" hint="same run" context="· government, tax, late, loans" :href="route('payroll')" />
                    {{-- More pay out isn't good or bad: no colour, just the direction. --}}
                    <x-ui.metric icon="arrows-right-left" label="Change" :value="$payChange === null ? '—' : ($payChange > 0 ? '+' : ($payChange < 0 ? '−' : '')).abs($payChange).'%'"
                        :hint="$payChange === null ? 'first run with payslips' : 'vs the run before'" :context="$payroll['previous_net'] !== null ? '('.$peso((float) $payroll['previous_net']).')' : null" :href="route('payroll')" />
                </x-ui.metric-strip>
            @endif

            {{-- Each open run: where it is in draft → processing → approved → paid, when pay goes out,
                 and how many payslips are still missing. --}}
            <x-ui.card :title="collect($this->payrollRuns)->contains(fn (array $run) => $run['status'] !== \App\Enums\PayrollRunStatus::Paid) ? 'Runs to finish' : 'Latest run'">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($this->payrollRuns as $run)
                        <li wire:key="payroll-run-{{ $run['id'] }}">
                            <a href="{{ route('payroll', ['run' => $run['id']]) }}" wire:navigate class="grid grid-cols-1 items-center gap-3 px-5 py-4 transition-colors hover:bg-gray-50 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_11rem] sm:gap-6 dark:hover:bg-white/[0.03]">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ $run['period'] }}</span>
                                    <span class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                        @if ($run['late'])<span class="h-1.5 w-1.5 rounded-full bg-red-500" aria-hidden="true"></span>@endif
                                        Pay day {{ $run['payDay'] }} · <span @class(['font-medium text-gray-700 dark:text-gray-300' => $run['late']])>{{ $run['payHint'] }}</span>
                                    </span>
                                </span>

                                {{-- The four steps: done ones filled, the current one ringed. --}}
                                <span class="flex items-center px-4 pb-5" aria-label="Status: {{ $run['status']->label() }}">
                                    @foreach ($steps as $i => $step)
                                        @if ($i > 0)
                                            <span @class(['h-0.5 flex-1', 'bg-blue-500' => $i <= $run['step'], 'bg-gray-200 dark:bg-gray-700' => $i > $run['step']]) aria-hidden="true"></span>
                                        @endif
                                        <span class="relative flex flex-col items-center" aria-hidden="true">
                                            <span @class([
                                                'h-2.5 w-2.5 rounded-full',
                                                'bg-blue-500' => $i < $run['step'],
                                                'bg-blue-500 ring-4 ring-blue-500/20' => $i === $run['step'],
                                                'bg-gray-200 dark:bg-gray-700' => $i > $run['step'],
                                            ])></span>
                                            <span @class(['absolute top-4 text-[11px] whitespace-nowrap', 'font-medium text-gray-800 dark:text-gray-100' => $i === $run['step'], 'text-gray-400 dark:text-gray-500' => $i !== $run['step']])>{{ $step->label() }}</span>
                                        </span>
                                    @endforeach
                                </span>

                                <span class="flex items-center justify-between gap-4 pt-4 text-right sm:justify-end sm:pt-0">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        <span class="block text-sm font-medium text-gray-800 tabular-nums dark:text-gray-100">{{ $run['payslips'] }} payslips</span>
                                        @if ($run['missing'] > 0)
                                            <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>{{ $run['missing'] }} still to generate</span>
                                        @elseif ($run['payslips'] === 0)
                                            none yet
                                        @else
                                            all generated
                                        @endif
                                    </span>
                                    <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-gray-300" />
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </section>
    @endif

    {{-- Birthdays this month (HR: everyone; Operations: guards, a Satellite its region's). Day and month only,
         never the age; someone who keeps their birthday private never shows (BirthdaysQuery). --}}
    @if ($this->birthdays !== [])
        <x-ui.card id="birthdays" title="Birthdays this month" :description="now()->format('F').' · a greeting goes a long way'">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($this->birthdays as $birthday)
                    @php
                        $person = $birthday['profile'];
                        $isToday = $birthday['on']->isToday();
                        $isPast = $birthday['on']->isPast() && ! $isToday;
                    @endphp
                    <li wire:key="birthday-{{ $person->id }}" @class(['flex items-center justify-between gap-4 px-5 py-3 text-sm', 'opacity-60' => $isPast])>
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-gray-800 dark:text-gray-100">{{ $person->name }}</span>
                            <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $person->is_office_staff ? 'Office staff' : ($person->detachment ?? 'Not deployed yet') }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2 tabular-nums text-gray-600 dark:text-gray-300">
                            @if ($isToday)<x-ui.badge tone="amber">Today</x-ui.badge>@endif
                            {{ $birthday['on']->format('D, M j') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    {{-- Where the people are: region → province → client. --}}
    @if ($this->canSeePeople() && $this->workforce['total']['people'] > 0)
        @php
            $workforce = $this->workforce;
            $cols = $showAttendance ? 'grid-cols-[minmax(0,1fr)_3.5rem_3.5rem] sm:grid-cols-[minmax(0,1fr)_repeat(5,4.5rem)_6.5rem]' : 'grid-cols-[minmax(0,1fr)_4.5rem]';
            // "5 provinces · 8 clients": what's inside a row before opening it.
            $plural = fn (int $n, string $word): string => $n.' '.str($word)->plural($n);
        @endphp
        <x-ui.card title="People by area" :description="'Where everyone is deployed (the client site, not their home address)'.($showAttendance ? ', and who is in today.' : '.')">
            <div class="text-sm">
                <div class="grid {{ $cols }} items-center gap-2 border-b border-gray-100 bg-gray-50/60 px-5 py-2 text-xs font-medium text-gray-500 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-400">
                    <span>Region · province · client</span>
                    <span class="text-right">People</span>
                    @if ($showAttendance)
                        <span class="text-right">In</span>
                        <span class="hidden text-right sm:block">Late</span>
                        <span class="hidden text-right sm:block">Not in yet</span>
                        <span class="hidden text-right sm:block">On leave</span>
                        <span class="hidden text-right sm:block" title="Clocked in, out of everyone expected (not on leave)">% in</span>
                    @endif
                </div>

                @foreach ($workforce['regions'] as $region)
                    {{-- Closed by default except when there's only one region (a Satellite Operation's). --}}
                    <details wire:key="area-{{ $region['value'] }}" class="group/region border-b border-gray-100 dark:border-gray-800" @if (count($workforce['regions']) === 1) open @endif>
                        <summary class="group/row grid {{ $cols }} cursor-pointer list-none items-center gap-2 px-5 py-3 hover:bg-gray-50 dark:hover:bg-white/5">
                            @include('livewire.admin.partials.area-row-name', ['name' => $region['label'], 'level' => 0, 'href' => $this->opensRegion($region['value']) ? route('employees', ['region' => $region['value']]) : null,
                                'hint' => $plural(count($region['provinces']), 'province').' · '.$plural(collect($region['provinces'])->sum(fn (array $province) => count($province['clients'])), 'client')])
                            @include('livewire.admin.partials.area-row-counts', ['counts' => $region['counts'], 'strong' => true])
                        </summary>
                        {{-- Indent guides, as in VS Code's file tree: a thin line under the open row's arrow
                             (lined up with the icon's centre, measured), down its children. Darker while hovered. --}}
                        <div class="relative pb-1 before:pointer-events-none before:absolute before:inset-y-0 before:left-[29.5px] before:w-px before:bg-gray-200 hover:before:bg-gray-300 dark:before:bg-gray-800 dark:hover:before:bg-gray-600">
                        @foreach ($region['provinces'] as $province)
                            <details wire:key="area-{{ $region['value'] }}-{{ $province['name'] }}" class="group/province" @if (count($region['provinces']) === 1) open @endif>
                                <summary class="group/row grid {{ $cols }} cursor-pointer list-none items-center gap-2 py-2.5 pr-5 pl-10 hover:bg-gray-50 dark:hover:bg-white/5">
                                    @include('livewire.admin.partials.area-row-name', ['name' => $province['name'] !== '' ? $province['name'] : 'No province', 'level' => 1, 'href' => $this->opensRegion($region['value']) ? route('employees', ['region' => $region['value'], 'province' => $province['name']]) : null,
                                        'hint' => $plural(count($province['clients']), 'client')])
                                    @include('livewire.admin.partials.area-row-counts', ['counts' => $province['counts'], 'strong' => false])
                                </summary>
                                {{-- The province's guide, under its arrow. --}}
                                <div class="relative before:pointer-events-none before:absolute before:inset-y-0 before:left-[49.5px] before:w-px before:bg-gray-200 hover:before:bg-gray-300 dark:before:bg-gray-800 dark:hover:before:bg-gray-600">
                                @foreach ($province['clients'] as $client)
                                    <div wire:key="area-client-{{ $client['id'] }}" class="grid {{ $cols }} items-center gap-2 py-2.5 pr-5 pl-[5.5rem] text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                                        @if ($this->opensRegion($region['value']))
                                            <a href="{{ route('employees', ['region' => $region['value'], 'province' => $province['name'], 'client' => $client['id']]) }}" wire:navigate class="truncate hover:text-blue-600 dark:hover:text-blue-400">{{ $client['name'] }}</a>
                                        @else
                                            <span class="truncate">{{ $client['name'] }}</span>
                                        @endif
                                        @include('livewire.admin.partials.area-row-counts', ['counts' => $client['counts'], 'strong' => false])
                                    </div>
                                @endforeach
                                </div>
                            </details>
                        @endforeach
                        </div>
                    </details>
                @endforeach

                @foreach ([['Office staff', $workforce['office'], route('employees', ['region' => 'office'])], ['Not deployed yet', $workforce['undeployed'], route('employees')]] as [$label, $counts, $href])
                    @if ($counts['people'] > 0)
                        <div wire:key="area-{{ $label }}" class="group/row grid {{ $cols }} items-center gap-2 border-b border-gray-100 px-5 py-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                            @include('livewire.admin.partials.area-row-name', ['name' => $label, 'level' => 0, 'href' => $href, 'plain' => true])
                            @include('livewire.admin.partials.area-row-counts', ['counts' => $counts, 'strong' => true])
                        </div>
                    @endif
                @endforeach

                <div class="grid {{ $cols }} items-center gap-2 px-5 py-3 font-semibold text-gray-800 dark:text-gray-100">
                    <span>Total</span>
                    @include('livewire.admin.partials.area-row-counts', ['counts' => $workforce['total'], 'strong' => true])
                </div>
            </div>
        </x-ui.card>
    @endif

    @if ($showAttendance)
        <x-ui.card title="Today's DTR (Daily Time Record)" :description="($this->hasAreaFilter() ? 'The area chosen above' : 'All posts and detachments').' · '.now()->format('l, F j')">
            <x-slot:actions>
                <label for="dtr-search" class="sr-only">Search</label>
                <input id="dtr-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search name or post…" class="ui-field mt-0! sm:w-56">
                <x-ui.select wire:model.live="statusFilter" aria-label="Filter by status">
                    <option value="all">All statuses</option>
                    <option value="on_time">On time</option>
                    <option value="late">Late</option>
                    <option value="absent">Not in yet</option>
                    <option value="clocked_in">Still clocked in</option>
                    <option value="on_leave">On leave</option>
                </x-ui.select>
            </x-slot:actions>

            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Employee</th><th class="ui-th">Detachment</th><th class="ui-th">Post</th><th class="ui-th">Shift</th><th class="ui-th">Time in</th><th class="ui-th">Time out</th><th class="ui-th">Hours</th><th class="ui-th">Status</th></tr></thead>
                    <tbody>
                        @forelse ($this->filtered as $row)
                            <tr wire:key="row-{{ $row['id'] }}">
                                <td class="ui-td">
                                    <span class="flex items-center gap-2">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700 dark:text-blue-400">{{ $row['initials'] }}</span>
                                        {{-- A link only to records this person may open (a Satellite: its region's). --}}
                                        <x-person-link :profile-id="$this->opensRegion($row['region']) ? $row['profileId'] : null" :name="$row['name']" />
                                    </span>
                                </td>
                                <td class="ui-td">{{ $row['detachment'] ?? '—' }}</td>
                                <td class="ui-td">{{ $row['post'] ?? '—' }}</td>
                                <td class="ui-td">{{ $row['shift'] ?? 'Default' }}</td>
                                <td class="ui-td">{{ $row['clockIn'] ?? '—' }}</td>
                                <td class="ui-td">{{ $row['clockOut'] ?? '—' }}</td>
                                <td class="ui-td">{{ $row['hours'] ?? '—' }}</td>
                                <td class="ui-td"><x-ui.status-badge :status="$row['status'] === 'pending' ? 'not_in' : $row['status']" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="ui-td py-8 text-center">Nobody matches your filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
