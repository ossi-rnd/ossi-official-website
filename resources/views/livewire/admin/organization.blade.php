@php
    ['chart' => $chart, 'field' => $field] = $this->organization;
    $totalGuards = array_sum(array_column($field, 'guards'));
    $totalClients = array_sum(array_column($field, 'clients'));
@endphp
<div class="space-y-6">
    {{-- Headquarters: the chart, drawn with .org-* connectors (resources/css/app.css). Scrolls sideways on a phone. --}}
    <x-ui.card title="Headquarters" description="Who holds each role. Names open their employee record. The Board lists those HR marked Member of the Board on their record, plus the President, who must be a director. President and Vice President are officers the Board elects, set by position. Everyone else by role (Roles & Access).">
        <p class="px-4 pt-3 text-xs text-gray-400 sm:hidden">Swipe sideways to see the whole chart.</p>
        {{-- Wider than a phone: start centred on the top of the chart. --}}
        <div class="overflow-x-auto" x-data x-init="$el.scrollLeft = ($el.scrollWidth - $el.clientWidth) / 2">
            <div class="org-chart mx-auto flex w-max min-w-full flex-col items-center px-4 py-6">
                {{-- The top line, one under the other: Board of Directors → President → Vice President. --}}
                @include('livewire.admin.partials.org-box', ['box' => $chart['board'], 'tone' => 'hexagon'])
                <div class="org-line"></div>
                @include('livewire.admin.partials.org-box', ['box' => $chart['president'], 'tone' => 'pill'])
                <div class="org-line"></div>
                @include('livewire.admin.partials.org-box', ['box' => $chart['vicePresident'], 'tone' => 'pill'])
                <div class="org-line"></div>

                @include('livewire.admin.partials.org-box', ['box' => $chart['superAdmin']])
                <div class="org-line"></div>

                <div class="org-row">
                    @foreach ($chart['departments'] as $department)
                        <div class="org-cell">
                            @include('livewire.admin.partials.org-box', ['box' => $department, 'tone' => $loop->last ? 'blue' : 'plain'])
                            @if ($loop->last)
                                {{-- Operation HQ → Satellite Operation → the field (below). --}}
                                <div class="org-line"></div>
                                @include('livewire.admin.partials.org-box', ['box' => $chart['satellite'], 'tone' => 'blue'])
                                <div class="org-line"></div>
                                <a href="#field" class="inline-flex items-center gap-1 rounded-full border border-blue-200 bg-white px-3 py-1 text-xs font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-500/30 dark:bg-gray-900 dark:text-blue-300">
                                    Regional → Provincial → clients
                                    <x-icon name="chevron-down" class="h-3.5 w-3.5" />
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </x-ui.card>

    {{-- The field side: region, its Satellite officers, provinces, clients, guards. --}}
    <x-ui.card id="field" title="Field operations" :description="count($field).' '.str('region')->plural(count($field)).' · '.$totalClients.' '.str('client')->plural($totalClients).' · '.$totalGuards.' '.str('guard')->plural($totalGuards).' deployed. Open a region, then a client to see its guards.'">
        @if ($field === [])
            <p class="px-6 py-10 text-center text-sm text-gray-500">No clients yet. Super Admin adds them under Clients.</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($field as $row)
                    <li wire:key="region-{{ $row['region']->value }}">
                        <details class="group" @if (count($field) === 1) open @endif>
                            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-gray-50 sm:px-6 dark:hover:bg-white/5 [&::-webkit-details-marker]:hidden">
                                <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-90" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $row['region']->label() }} <span class="font-normal text-gray-500">· Regional</span></span>
                                    <span class="block text-xs text-gray-500">
                                        Satellite Operation:
                                        @forelse ($row['satellites'] as $officer)
                                            <x-person-link :user="$officer" class="text-xs!" />@if (! $loop->last), @endif
                                        @empty
                                            <span class="text-amber-700 dark:text-amber-400">nobody assigned</span>
                                        @endforelse
                                    </span>
                                </span>
                                <span class="flex flex-wrap gap-1.5 text-xs">
                                    <x-ui.badge tone="gray">{{ count($row['provinces']) }} {{ str('province')->plural(count($row['provinces'])) }}</x-ui.badge>
                                    <x-ui.badge tone="gray">{{ $row['clients'] }} {{ str('client')->plural($row['clients']) }}</x-ui.badge>
                                    <x-ui.badge tone="blue">{{ $row['guards'] }} {{ str('guard')->plural($row['guards']) }}</x-ui.badge>
                                </span>
                            </summary>

                            <div class="space-y-3 bg-gray-50/60 px-4 py-4 sm:px-6 sm:pl-14 dark:bg-white/[0.02]">
                                @foreach ($row['provinces'] as $province)
                                    <div wire:key="province-{{ $row['region']->value }}-{{ $province['name'] }}" class="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                                        <p class="flex items-center justify-between gap-3 border-b border-gray-100 px-3 py-2 text-sm dark:border-gray-800">
                                            <span class="font-medium text-gray-800 dark:text-gray-100">{{ $province['name'] }} <span class="font-normal text-gray-500">· Provincial</span></span>
                                            <span class="text-xs text-gray-500">{{ $province['guards'] }} {{ str('guard')->plural($province['guards']) }}</span>
                                        </p>
                                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                                            @foreach ($province['clients'] as ['client' => $client, 'guards' => $guards])
                                                <li wire:key="client-{{ $client->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                                    <span class="flex min-w-0 items-center gap-2">
                                                        <x-icon name="building" class="h-4 w-4 shrink-0 text-gray-400" />
                                                        <span class="truncate text-gray-700 dark:text-gray-200">{{ $client->name }}</span>
                                                    </span>
                                                    @if ($guards > 0)
                                                        <button type="button" wire:click="showClient({{ $client->id }})" class="shrink-0 text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ $guards }} {{ str('guard')->plural($guards) }}</button>
                                                    @else
                                                        <span class="shrink-0 text-xs text-gray-400">No guards</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    @if ($this->openClient)
        <x-ui.modal :title="$this->openClient->name.' · '.$this->guards->count().' '.str('guard')->plural($this->guards->count())" close="closeClient">
            <p class="mb-3 text-xs text-gray-500">{{ $this->openClient->locationLabel() }}</p>
            <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
                @foreach ($this->guards as $guard)
                    <li wire:key="guard-{{ $guard->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <span class="min-w-0">
                            <x-person-link :profile="$guard" />
                            <span class="block truncate text-xs text-gray-500">{{ $guard->post ?? 'No post' }}{{ $guard->position ? ' · '.$guard->position->label() : '' }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-gray-500">{{ $guard->shift?->timeRange() ?? '' }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.modal>
    @endif
</div>
