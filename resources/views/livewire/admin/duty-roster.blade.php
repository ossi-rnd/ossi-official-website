<div class="space-y-6">
    @php
        $days = $this->days;
        $today = today();
    @endphp

    <x-ui.card title="Duty Roster" description="Each guard's duty per day. Clock-ins are matched to it: late, night differential and overtime follow the duty, and a guard with no duty that day can't clock in. Click a day to change it.">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.select wire:model.live="clientFilter" aria-label="Client">
                    <option value="">All clients</option>
                    @foreach ($this->clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </x-ui.select>
                <div class="flex items-center rounded-md border border-gray-300 dark:border-gray-700">
                    <button type="button" wire:click="shiftWeek(-1)" class="px-2 py-2 text-gray-500 hover:text-gray-800 dark:hover:text-white" aria-label="Previous week"><x-icon name="chevron-left" class="h-4 w-4" /></button>
                    <button type="button" wire:click="thisWeek" class="border-x border-gray-300 px-3 py-1.5 text-sm font-medium whitespace-nowrap text-gray-700 dark:border-gray-700 dark:text-gray-200">{{ $days[0]->format('M j') }} – {{ $days[6]->format('M j, Y') }}</button>
                    <button type="button" wire:click="shiftWeek(1)" class="px-2 py-2 text-gray-500 hover:text-gray-800 dark:hover:text-white" aria-label="Next week"><x-icon name="chevron-right" class="h-4 w-4" /></button>
                </div>
            </div>
        </x-slot:actions>

        <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3 text-sm dark:border-gray-800">
            <span class="text-gray-500">Fill the empty days of this week:</span>
            <x-ui.button type="button" variant="secondary" size="sm" wire:click="fillWeek('copy')" wire:confirm="Copy last week's duties into the empty days of this week?">Copy last week</x-ui.button>
            <x-ui.button type="button" variant="secondary" size="sm" wire:click="fillWeek('usual')" wire:confirm="Fill the empty days Monday to Saturday with each guard's usual shift (Sunday off)?">Usual shifts, Sunday off</x-ui.button>
            <span class="text-xs text-gray-400">Days already set and past days are never changed.</span>
        </div>

        @error('fill') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        <div class="overflow-x-auto">
            <table class="ui-table min-w-[64rem]">
                <thead>
                    <tr>
                        <th class="ui-th sticky left-0 z-10 bg-gray-50 dark:bg-gray-900">Guard</th>
                        @foreach ($days as $day)
                            <th @class(['ui-th text-center', 'text-blue-600 dark:text-blue-400' => $day->isSameDay($today)])>{{ $day->format('D') }}<br><span class="font-normal">{{ $day->format('M j') }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->guards as $guard)
                        <tr wire:key="roster-{{ $guard->id }}">
                            <td class="ui-td sticky left-0 z-10 bg-white dark:bg-gray-900">
                                <p class="whitespace-nowrap"><x-person-link :user="$guard" /></p>
                                <p class="max-w-44 truncate text-xs text-gray-500" title="{{ $guard->employeeProfile?->assignmentLabel() }}">{{ $guard->employeeProfile?->assignmentLabel() }}</p>
                            </td>
                            @foreach ($days as $day)
                                @php
                                    $key = $guard->id.'|'.$day->toDateString();
                                    $duty = $this->duties->get($key);
                                    $log = $this->logs->get($key);
                                    $past = $day->lt($today);
                                    $usualClient = $guard->employeeProfile?->client_id;
                                @endphp
                                <td class="ui-td p-1 align-top">
                                    @if ($past)
                                        <div class="min-h-14 rounded-md px-2 py-1.5 text-xs {{ $duty ? 'bg-gray-50 dark:bg-gray-800/60' : '' }}">
                                            @if ($duty)
                                                <p class="font-medium text-gray-700 dark:text-gray-200">{{ $duty->shift->timeRange() }}</p>
                                            @endif
                                            @if ($log)
                                                <p class="text-gray-500">{{ $log->clock_in_at?->format('H:i') ?? '—' }} → {{ $log->clock_out_at?->format('H:i') ?? '…' }}</p>
                                                @if ($log->late_minutes > 0) <p class="text-amber-700 dark:text-amber-400">{{ \App\Support\Duration::minutes($log->late_minutes, short: true) }} late</p> @endif
                                                @if ($log->isManual()) <x-ui.badge tone="purple">Manual</x-ui.badge> @endif
                                            @elseif ($duty)
                                                <p class="font-medium text-red-600 dark:text-red-400">Absent</p>
                                            @else
                                                <p class="text-gray-300 dark:text-gray-600">Off</p>
                                            @endif
                                            @if ($duty && (! $log || $log->isManual()))
                                                <button type="button" wire:click="startManual({{ $duty->id }})" class="mt-0.5 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ $log ? 'Edit record' : 'Record by hand' }}</button>
                                            @endif
                                        </div>
                                    @else
                                        <button type="button" wire:click="editCell({{ $guard->id }}, '{{ $day->toDateString() }}')"
                                            @class([
                                                'block min-h-14 w-full rounded-md border px-2 py-1.5 text-left text-xs transition-colors',
                                                'border-blue-200 bg-blue-50 hover:border-blue-400 dark:border-blue-500/30 dark:bg-blue-500/10' => $duty && ! $duty->isRelief(),
                                                'border-purple-200 bg-purple-50 hover:border-purple-400 dark:border-purple-500/30 dark:bg-purple-500/10' => $duty?->isRelief(),
                                                'border-dashed border-gray-200 text-gray-400 hover:border-gray-400 dark:border-gray-700' => ! $duty,
                                            ])>
                                            @if ($duty)
                                                <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $duty->shift->timeRange() }}</span>
                                                <span class="block truncate text-gray-500" title="{{ $duty->placeLabel() }}">{{ $duty->client_id !== $usualClient ? $duty->placeLabel() : ($duty->post ?? $duty->shift->name) }}</span>
                                                @if ($duty->isRelief()) <span class="font-medium text-purple-700 dark:text-purple-300">Relief</span> @endif
                                                @if ($duty->patrols) <span class="font-medium text-blue-700 dark:text-blue-300">Patrol</span> @endif
                                                @if ($log) <span class="block text-blue-700 dark:text-blue-300">In {{ $log->clock_in_at?->format('H:i') }}</span> @endif
                                            @else
                                                Off
                                            @endif
                                        </button>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="8" class="ui-td py-8 text-center">No guards here{{ auth()->user()?->isRegionallyLimited() ? ' in your region' : '' }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer><p class="text-xs text-gray-500">Blue: duty · Purple: relief duty (set on Relievers) · Dashed: day off. A guard never put on the roster keeps clocking in on their usual shift.</p></x-slot:footer>
    </x-ui.card>

    @if ($this->editingGuard)
        @php $cellDuty = $this->duties->get($editUserId.'|'.$editDate); @endphp
        <x-ui.modal :title="$this->editingGuard->name.' · '.\Carbon\CarbonImmutable::parse($editDate)->format('D, M j')" close="closeCell">
            <form id="duty-form" wire:submit="saveCell" class="space-y-4">
                <x-ui.select label="Duty" wire:model.live="editShiftId">
                    <option value="">Choose the duty</option>
                    @foreach ($this->shifts as $shift)
                        <option value="{{ $shift->id }}">{{ $shift->label() }}</option>
                    @endforeach
                    {{-- Hours not in the list: typed, like HR's employee form (saved as a reusable shift). --}}
                    <option value="{{ \App\Livewire\Admin\DutyRoster::CUSTOM_SHIFT }}">Custom time…</option>
                </x-ui.select>
                @if ($editShiftId === \App\Livewire\Admin\DutyRoster::CUSTOM_SHIFT)
                    <x-shift-hours wire:key="duty-custom-shift" start="editCustomStart" end="editCustomEnd" grace="editCustomGrace" />
                @endif
                <x-ui.select label="Client" wire:model.live="editClientId">
                    <option value="">No client</option>
                    @foreach ($this->clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }} · {{ $client->locationLabel() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Post" wire:model="editPost" placeholder="e.g. Main Entrance" />
                {{-- Stays at the post / roving: chosen in the browser, sent with "Save duty". --}}
                <fieldset>
                    <legend class="text-sm font-medium text-gray-700 dark:text-gray-200">Type of duty</legend>
                    <div class="mt-1.5 grid gap-2 sm:grid-cols-2" role="radiogroup">
                        @foreach ([[false, 'map-pin', 'Stays at the post', 'Guards one spot for the whole duty.'], [true, 'shield', 'Roving patrol', 'Walks the site and scans every checkpoint each round.']] as [$roving, $icon, $title, $hint])
                            <button type="button" role="radio" x-on:click="$wire.editPatrols = @js($roving)"
                                x-bind:aria-checked="($wire.editPatrols === @js($roving)).toString()"
                                x-bind:class="$wire.editPatrols === @js($roving) ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-600 dark:bg-blue-500/10' : 'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600'"
                                class="flex items-start gap-3 rounded-lg border p-3 text-left transition">
                                <span x-bind:class="$wire.editPatrols === @js($roving) ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400'" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md">
                                    <x-icon :name="$icon" class="h-4 w-4" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ $title }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>

                    {{-- What roving means at this client. --}}
                    <div x-show="$wire.editPatrols" x-cloak class="mt-2">
                        @if ($this->patrolSetup === null)
                            <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">Choose a client: the checkpoints are the stickers at that client's site.</p>
                        @elseif ($this->patrolSetup['checkpoints'] === 0)
                            <x-ui.alert tone="amber">
                                {{ $this->patrolSetup['client']->name }} has no checkpoints yet, so there's nothing to scan.
                                <a href="{{ route('patrols', ['client' => $this->patrolSetup['client']->id]) }}" wire:navigate class="font-medium underline">Add them on Patrols</a>.
                            </x-ui.alert>
                        @else
                            @php
                                $minutes = $this->patrolSetup['interval'];
                                $every = $minutes % 60 === 0 ? ($minutes === 60 ? 'every hour' : 'every '.intdiv($minutes, 60).' hours') : "every {$minutes} min";
                            @endphp
                            <p class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                <x-icon name="clock" class="h-4 w-4 shrink-0 text-gray-400" />
                                <span>
                                    <span class="font-medium text-gray-800 dark:text-gray-100">{{ $this->patrolSetup['checkpoints'] }} {{ $this->patrolSetup['checkpoints'] === 1 ? 'checkpoint' : 'checkpoints' }}</span>,
                                    a full round {{ $every }}. A missed round alerts Operations.
                                    <a href="{{ route('patrols', ['client' => $this->patrolSetup['client']->id]) }}" wire:navigate class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Change on Patrols</a>
                                </span>
                            </p>
                        @endif
                    </div>
                </fieldset>
            </form>
            <x-slot:footer>
                <div class="flex w-full items-center justify-between gap-3">
                    @if ($cellDuty)
                        <x-ui.button type="button" variant="secondary" wire:click="dayOff" wire:confirm="Make this a day off? {{ $this->editingGuard->name }} won't be able to clock in.">Make it a day off</x-ui.button>
                    @else
                        <span></span>
                    @endif
                    <x-ui.button form="duty-form" wire:loading.attr="disabled" wire:target="saveCell">Save duty</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($this->manualDuty)
        <x-ui.modal :title="'Record attendance · '.$this->manualDuty->user->name" close="closeManual">
            <x-ui.alert tone="amber">Only when the guard couldn't punch (phone, GPS or coverage). It's marked "Manual" everywhere and written to the audit log with your name and reason.</x-ui.alert>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $this->manualDuty->duty_date->format('l, M j') }} · {{ $this->manualDuty->shift->label() }} · {{ $this->manualDuty->placeLabel() }}</p>
            <form id="manual-form" wire:submit="saveManual" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input label="Clock-in" type="datetime-local" wire:model="manualIn" />
                    <x-ui.input label="Clock-out (optional)" type="datetime-local" wire:model="manualOut" />
                </div>
                <x-ui.textarea label="Reason" wire:model="manualReason" rows="2" maxlength="1000" placeholder="e.g. Phone broken; detachment commander confirmed she was at the post 06:00–15:00." />
            </form>
            <x-slot:footer>
                <x-ui.button form="manual-form" wire:loading.attr="disabled" wire:target="saveManual">Save record</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
