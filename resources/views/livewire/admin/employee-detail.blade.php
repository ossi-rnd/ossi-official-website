@php $profile = $this->profile; @endphp

<div class="mx-auto max-w-4xl space-y-6">
    <a href="{{ route('employees') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-100">
        <x-icon name="chevron-left" class="h-4 w-4" /> Back to employees
    </a>

    <x-sensitive class="space-y-6">
    <div class="ui-card flex flex-col gap-4 p-6 sm:flex-row sm:items-center">
        @if ($profile->user)
            <x-ui.avatar :user="$profile->user" size="lg" />
        @else
            <span class="flex h-20 w-20 items-center justify-center rounded-full bg-gray-100 text-2xl font-semibold text-gray-400">?</span>
        @endif
        <div class="min-w-0 flex-1">
            <p class="text-xl font-semibold text-gray-800 dark:text-gray-100">{{ $profile->name }}</p>
            <p class="text-sm text-gray-500">{{ $profile->employee_number }} · {{ $profile->email }}</p>
            <div class="mt-2 flex flex-wrap gap-1.5">
                <x-ui.badge :tone="$profile->employment_status->isActive() ? 'gray' : 'red'">{{ $profile->employment_status->label() }}</x-ui.badge>
                <x-ui.badge :tone="$profile->is_office_staff ? 'indigo' : 'gray'">{{ $profile->is_office_staff ? 'Office staff' : 'Field staff' }}</x-ui.badge>
                @if ($profile->is_test_account)
                    <x-ui.badge tone="amber">Test account</x-ui.badge>
                @endif
                @foreach ($profile->user?->roleList() ?? [] as $role)
                    <x-ui.badge :tone="$role->tone()">{{ $role->label() }}</x-ui.badge>
                @endforeach
            </div>
        </div>
        @can(\App\Enums\Ability::ManagePeople->value)
            <x-ui.button type="button" variant="secondary" wire:click="edit"><x-icon name="pencil" class="h-4 w-4" /> Edit record</x-ui.button>
        @elsecan(\App\Enums\Ability::DeployGuards->value)
            @if ($profile->hasDeployment())
                <x-ui.button type="button" variant="secondary" wire:click="startDeployment"><x-icon name="map-pin" class="h-4 w-4" /> Change deployment</x-ui.button>
            @endif
        @endcan
    </div>

    @if (! $profile->isClaimed())
        <x-ui.alert tone="amber">
            Not registered yet. Give {{ $profile->name }} employee number <strong>{{ $profile->employee_number }}</strong>; they create their account at /register using <strong>{{ $profile->email }}</strong>.
        </x-ui.alert>
    @elseif ($profile->user?->email_verified_at === null)
        <x-ui.alert tone="blue">Registered, but hasn't clicked the email confirmation link yet.</x-ui.alert>
    @endif

    {{-- A guard's company phone (CheckPunchDevice): punches from another one are flagged, never refused. --}}
    @if ($profile->user && ! $profile->is_office_staff && auth()->user()?->can(\App\Enums\Ability::ManageGuardPhones->value))
        @php $phone = $profile->user->device; @endphp
        <div class="ui-card flex flex-wrap items-center justify-between gap-3 p-4">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon name="phone" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                <div class="min-w-0 text-sm">
                    <p class="font-medium text-gray-800 dark:text-gray-100">{{ $phone ? 'Company phone: '.($phone->label ?? 'registered') : 'No phone registered yet' }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($phone)
                            {{ $phone->registered_by ? 'Set by '.($phone->registeredBy?->name ?? 'a supervisor') : 'Registered by their first clock-in' }} on {{ $phone->registered_at->format('M j, Y') }}{{ $phone->last_used_at ? ' · last used '.$phone->last_used_at->diffForHumans() : '' }}. Clocking in on another phone is flagged.
                        @else
                            The phone they clock in with next becomes theirs.
                        @endif
                    </p>
                </div>
            </div>
            @if ($phone && \App\Models\UserDevice::canManage(auth()->user(), $profile->user))
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="forgetPhone" wire:confirm="Forget {{ $profile->name }}'s phone? Their next clock-in registers whatever phone they use (lost phone, or a new company phone).">Forget phone</x-ui.button>
            @endif
        </div>
    @endif

    @php
        $mask = fn (?string $value) => filled($value) ? str_repeat('•', max(0, strlen($value) - 4)).substr($value, -4) : '—';
        $canManage = auth()->user()?->can(\App\Enums\Ability::ManagePeople->value) ?? false;
        $sections = [
            'Assignment' => [
                'Position' => $profile->position?->label(),
                ...($profile->hasDeployment() ? ['Detachment' => $profile->detachment, 'Location' => $profile->client?->locationLabel(), 'Post' => $profile->post] : []),
                ...($profile->is_office_staff ? ['Office' => $profile->office ? $profile->office->name.' · '.$profile->office->locationLabel() : 'None set (clocks in anywhere)'] : []),
                'Shift' => $profile->shift?->label() ?? 'Default shift', 'Hire date' => $profile->hire_date?->format('M j, Y'),
            ],
            // Personal and sensitive details: HR only (and Accounting for the IDs payroll remits).
            ...($canManage ? ['Contact' => [
                'Contact number' => $profile->contact_number, 'Permanent address' => $profile->permanent_address,
                'Date of birth' => $profile->birthDate() === null ? null : $profile->birthDate()->format('M j, Y').($profile->birthday_private ? ' (birthday kept private)' : ''),
                'Emergency contact' => trim($profile->emergency_contact_name.' '.$profile->emergency_contact_phone),
            ]] : []),
            // Masked on screen; the full value is only shown inside the edit form.
            ...($canManage || (auth()->user()?->can(\App\Enums\Ability::ManagePayroll->value) ?? false) ? ['Government IDs' => [
                'SSS' => $mask($profile->sss_number), 'PhilHealth' => $mask($profile->philhealth_number),
                'Pag-IBIG' => $mask($profile->pagibig_number), 'TIN' => $mask($profile->tin_number),
                // Other IDs, masked the same way, under their own names.
                ...collect($profile->other_ids ?? [])->mapWithKeys(fn (array $id) => [$id['label'] => $mask($id['number'])])->all(),
            ]] : []),
            ...($canManage ? ['Medical' => ['Blood type' => $profile->blood_type, 'Conditions and allergies' => $profile->medical_conditions]] : []),
        ];
    @endphp

    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        @foreach ($sections as $heading => $fields)
            <x-ui.card :title="$heading">
                <dl class="space-y-3 p-4">
                    @foreach ($fields as $label => $value)
                        <div>
                            <dt class="text-xs font-medium tracking-wide text-gray-400 uppercase">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm text-gray-800 dark:text-gray-100">{{ filled($value) ? $value : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>
        @endforeach
    </div>

    @if ($this->trainingRecords->isNotEmpty())
        <x-ui.card title="Training">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($this->trainingRecords as $record)
                    <li wire:key="training-{{ $record->id }}" class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                        <span class="min-w-0">
                            <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $record->training->title }}</span>
                            <span class="block text-xs text-gray-500">{{ $record->training->dateLabel() }}{{ $record->training->valid_until ? ' · valid until '.$record->training->valid_until->format('M j, Y') : '' }}</span>
                        </span>
                        <x-ui.badge :tone="$record->status->tone()">{{ $record->status->label() }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if ($this->recentAways->isNotEmpty())
        @php
            $now = \Carbon\CarbonImmutable::now();
            $aways = $this->recentAways;
            $late = $aways->filter(fn ($away) => $away->minutesPastExpected($now) > 0 && $away->approved_at === null)->count();
            $neverEnded = $aways->whereNotNull('auto_closed_at')->count();
        @endphp
        <x-ui.card title="Time away · last 30 days" :description="$aways->count().' '.str('time')->plural($aways->count()).', '.$aways->sum(fn ($away) => $away->minutesAway($now)).' min in all'.($late > 0 ? ' · back after their time '.$late.'×' : '').($neverEnded > 0 ? ' · never ended '.$neverEnded.'×' : '')">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($aways as $away)
                    @php [$tone, $text] = $away->outcome($now); @endphp
                    <li wire:key="away-{{ $away->id }}" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-3 text-sm">
                        <span class="min-w-0">
                            <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $away->left_at->format('D, M j') }} · {{ $away->reason->label() }}</span>
                            <span class="block truncate text-xs text-gray-500">{{ $away->left_at->format('g:i A') }}–{{ $away->returned_at?->format('g:i A') ?? 'now' }} ({{ $away->minutesAway($now) }} min){{ $away->note ? ' · '.$away->note : '' }}</span>
                        </span>
                        <x-ui.badge :tone="$tone">{{ $text }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if ($this->recentAbsences->isNotEmpty())
        @php
            $absences = $this->recentAbsences;
            $noShows = $absences->filter->isNoShow()->count();
            $reported = $absences->count() - $noShows;
        @endphp
        <x-ui.card title="Absences · last 90 days" :description="$reported.' reported ahead'.' · '.$noShows.' not reported (no-show)'">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($absences as $absence)
                    <li wire:key="absence-{{ $absence->id }}" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-3 text-sm">
                        <span class="min-w-0">
                            <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $absence->duty_date->format('D, M j') }} · {{ $absence->placeLabel() }}</span>
                            <span class="block truncate text-xs text-gray-500">{{ $absence->reason }}{{ $absence->reliever ? ' · relieved by '.$absence->reliever->name : '' }}{{ $absence->arrived_at ? ' · clocked in '.$absence->arrived_at->format('g:i A') : '' }}</span>
                        </span>
                        <x-ui.badge :tone="$absence->isNoShow() ? 'red' : 'gray'">{{ $absence->isNoShow() ? 'Not reported' : 'Reported' }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @can(\App\Enums\Ability::ViewPayRates->value)
    @php
        $today = today();
        $inForce = $this->rates->first(fn ($rate) => $rate->effective_from->lessThanOrEqualTo($today));
        $paid = $this->paidRate;
    @endphp
    {{-- Accounting sets the rate on Daily Rates; HR sees it here. A raise is a new dated row, never an edit. --}}
    <x-ui.card title="Pay rate" :description="match ($paid->source) { \App\Enums\RateSource::Personal => 'Personal daily rate in force today.', \App\Enums\RateSource::Position => 'The company rate for '.$profile->position?->label().', in force today.', default => $paid->rate > 0 ? 'No rate yet: payroll uses the company default for '.($profile->is_office_staff ? 'office' : 'field').' staff until Accounting sets one.' : 'No rate yet: Accounting sets it on Daily Rates. Payroll can\'t generate a payslip until then.' }">
        <x-slot:actions>
            @can(\App\Enums\Ability::ManagePayroll->value)
                <x-ui.button :href="route('daily-rates', ['q' => $profile->employee_number])" variant="secondary" size="sm"><x-icon name="banknotes" class="h-4 w-4" /> Change rate</x-ui.button>
            @else
                <x-ui.button type="button" variant="secondary" size="sm" tooltip-align="end" disabled-reason="Daily rates are set by Accounting, who runs payroll. HR can see them here."><x-icon name="lock" class="h-4 w-4" /> Set by Accounting</x-ui.button>
            @endcan
        </x-slot:actions>
        <div class="space-y-3 p-4">
            <p class="text-2xl font-semibold text-gray-800 tabular-nums dark:text-gray-100">
                @if ($paid->rate > 0)
                    ₱{{ number_format($paid->rate, 2) }} <span class="text-sm font-normal text-gray-500">a day</span>
                    <x-ui.badge :tone="$paid->source === \App\Enums\RateSource::Default ? 'amber' : 'gray'" class="ml-1 align-middle">{{ $paid->source->label() }}</x-ui.badge>
                @else
                    <span class="text-amber-600 dark:text-amber-400">Not set</span>
                @endif
            </p>
            @if ($this->rates->isNotEmpty())
                <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-800">
                    <table class="ui-table">
                        <thead><tr><th class="ui-th">From</th><th class="ui-th text-right">Daily rate</th><th class="ui-th">Note</th><th class="ui-th">Set by</th></tr></thead>
                        <tbody>
                            @foreach ($this->rates as $rate)
                                <tr wire:key="rate-{{ $rate->id }}">
                                    <td class="ui-td whitespace-nowrap">
                                        {{ $rate->effective_from->format('M j, Y') }}
                                        @if ($rate->is($inForce))<x-ui.badge tone="emerald" class="ml-1">In force</x-ui.badge>@elseif ($rate->effective_from->greaterThan($today))<x-ui.badge tone="blue" class="ml-1">Upcoming</x-ui.badge>@endif
                                    </td>
                                    <td class="ui-td text-right font-medium tabular-nums">₱{{ number_format((float) $rate->daily_rate, 2) }}</td>
                                    <td class="ui-td">{{ $rate->note ?? '—' }}</td>
                                    <td class="ui-td">{{ $rate->setter->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </x-ui.card>

    @endcan

    @can('viewRequirements', $profile)
        <livewire:people.requirements-checklist :profile-id="$profile->id" :key="'requirements-'.$profile->id" />
    @endcan

    {{-- Position history (roadmap 22): promotions and changes of position; HR promotes here. --}}
    <livewire:people.position-history :profile-id="$profile->id" :key="'positions-'.$profile->id" />

    {{-- Notices to Explain (roadmap 30): kept on the 201 file, for whoever may read them. --}}
    @if ($profile->user && \App\Models\NoticeToExplain::canView(auth()->user(), $profile->user))
        @php
            $notices = \App\Models\NoticeToExplain::query()->where('user_id', $profile->user->id)->latest('id')->get();
            $canIssue = \App\Models\NoticeToExplain::canIssue(auth()->user(), $profile->user);
        @endphp
        <x-ui.card title="Notices to Explain" :description="$notices->isEmpty() ? 'None.' : $notices->count().' in all · '.$notices->filter->isDecided()->count().' decided'">
            <x-slot:actions>
                @if ($canIssue)
                    <a href="{{ route('notices-to-explain', ['issue' => $profile->user->id]) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Issue one</a>
                @endif
            </x-slot:actions>
            @if ($notices->isNotEmpty())
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($notices as $nte)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                            <span>
                                <span class="block text-gray-800 dark:text-gray-100">{{ $nte->subject }}</span>
                                <span class="block text-xs text-gray-500">{{ $nte->incident_date->format('M j, Y') }} · {{ $nte->stage() }}</span>
                            </span>
                            @can(\App\Enums\Ability::IssueNoticesToExplain->value)
                                <a href="{{ route('notices-to-explain', ['open' => $nte->id, 'show' => 'all']) }}" wire:navigate class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Open</a>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif

    {{-- Merits and demerits (roadmap 21): whoever supervises them, HR and the President or Vice President. --}}
    @if ($profile->user && \App\Models\MeritRecord::canView(auth()->user(), $profile->user))
        <livewire:people.merit-history :user-id="$profile->user->id" :key="'merits-'.$profile->user->id" />
    @endif
    </x-sensitive>

    @if ($editing)
        <x-ui.modal title="Edit employee record" close="$set('editing', false)" max-width="2xl">
            <form id="edit-employee" wire:submit="save">
                @include('livewire.admin.partials.employee-form')
            </form>

            <x-slot:footer>
                <x-ui.button form="edit-employee" wire:loading.attr="disabled" wire:target="save">Save changes</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($deploying)
        <x-ui.modal title="Change deployment" close="$set('deploying', false)">
            <form id="deploy-employee" wire:submit="saveDeployment" class="space-y-4">
                <x-ui.select label="Detachment (client)" wire:model="deployClientId">
                    <option value="">Not deployed</option>
                    @foreach ($this->deployableClients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }} · {{ $client->locationLabel() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Post" wire:model="deployPost" placeholder="e.g. Main Entrance" />
                <x-ui.select label="Shift" wire:model="deployShiftId">
                    <option value="">Default shift</option>
                    @foreach ($this->shifts as $shift)
                        <option value="{{ $shift->id }}">{{ $shift->label() }}</option>
                    @endforeach
                </x-ui.select>
            </form>
            <x-slot:footer>
                <x-ui.button form="deploy-employee" wire:loading.attr="disabled" wire:target="saveDeployment">Save deployment</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

</div>
