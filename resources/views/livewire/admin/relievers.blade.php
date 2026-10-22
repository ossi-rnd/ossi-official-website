<div class="space-y-6">
    <x-ui.card title="Relievers" description="Guards who said they can't report, and no-shows: on the roster, past their grace period, no clock-in and no notice. Send someone to cover the post; both guards are notified.">
        <x-slot:actions>
            <div class="flex gap-1" role="tablist" aria-label="Show">
                @foreach (['open' => 'Needs reliever', 'covered' => 'Covered', 'not_covered' => 'Not covered', 'all' => 'All'] as $value => $label)
                    <button type="button" role="tab" aria-selected="{{ $filter === $value ? 'true' : 'false' }}" wire:click="$set('filter', '{{ $value }}')"
                        @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-gray-900 text-white dark:bg-white dark:text-gray-800' => $filter === $value, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' => $filter !== $value])>{{ $label }}</button>
                @endforeach
            </div>
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Duty date</th><th class="ui-th">Absent guard</th><th class="ui-th">Post</th><th class="ui-th">Reason</th><th class="ui-th">Reliever</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->notices as $notice)
                        @php $past = $notice->duty_date->lt(today()); @endphp
                        <tr wire:key="notice-{{ $notice->id }}">
                            <td class="ui-td whitespace-nowrap">
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ $notice->duty_date->format('D, M j') }}</p>
                                <p class="text-xs text-gray-500">{{ $notice->duty_date->isToday() ? 'Today' : ($past ? 'Past' : $notice->duty_date->diffForHumans(today(), ['syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW, 'parts' => 1])) }}</p>
                            </td>
                            <td class="ui-td">
                                <p><x-person-link :user="$notice->user" /></p>
                                <x-filed-at :at="$notice->created_at" />
                            </td>
                            <td class="ui-td">{{ $notice->placeLabel() }}</td>
                            <td class="ui-td max-w-xs">
                                @if ($notice->isNoShow())
                                    <x-ui.badge tone="red">No-show</x-ui.badge>
                                @endif
                                <p class="truncate" title="{{ $notice->reason }}">{{ $notice->reason }}</p>
                                @if ($notice->arrived_at)
                                    <p class="text-xs text-gray-500">Clocked in {{ $notice->arrived_at->format('g:i A') }}</p>
                                @endif
                            </td>
                            <td class="ui-td">
                                @if ($notice->reliever)
                                    <p><x-person-link :user="$notice->reliever" /></p>
                                    <p class="text-xs text-gray-500">by {{ $notice->coveredBy->name ?? '—' }}</p>
                                    {{-- The reliever's fare (Deployment Costs): its status once claimed, else a link to claim it. --}}
                                    @if ($fare = $notice->activeFareClaim())
                                        <p class="text-xs text-gray-500">Fare claimed ₱{{ number_format((float) $fare->amount, 2) }} · {{ $fare->status->label() }}</p>
                                    @elseif ($notice->fareClaimable() && auth()->user()?->can(\App\Enums\Ability::FileDeploymentClaims->value))
                                        <a href="{{ route('deployment-claims', ['relief' => $notice->id]) }}" wire:navigate class="text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Claim the reliever's fare</a>
                                    @endif
                                @else
                                    <x-ui.badge :tone="$notice->status->tone()">{{ $notice->status->label() }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="ui-td text-right whitespace-nowrap">
                                @if (! in_array($notice->status, [\App\Enums\AbsenceNoticeStatus::Cancelled, \App\Enums\AbsenceNoticeStatus::Arrived], true) && ! $past)
                                    <button type="button" wire:click="startAssign({{ $notice->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ $notice->reliever ? 'Change' : 'Assign reliever' }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-td py-8 text-center">{{ $filter === 'open' ? 'Every post is covered.' : 'No notices here.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>{{ $this->notices->links() }}</x-slot:footer>
    </x-ui.card>

    @if ($this->assigning)
        @php
            $absent = $this->assigning;
            $window = $absent->dutyWindow();
            $absentPhone = $absent->user->employeeProfile?->contact_number;
            $closeness = [
                \App\Queries\Operations\ReliefCandidatesQuery::SAME_SITE => ['Same site', 'blue'],
                \App\Queries\Operations\ReliefCandidatesQuery::SAME_PROVINCE => ['Same province', 'blue'],
                \App\Queries\Operations\ReliefCandidatesQuery::SAME_REGION => ['Same region', 'gray'],
                \App\Queries\Operations\ReliefCandidatesQuery::ELSEWHERE => ['Other region', 'gray'],
            ];
        @endphp
        <x-ui.modal title="Assign a reliever" close="cancelAssign">
            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-gray-800/50">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-800 dark:text-gray-100">{{ $absent->placeLabel() }}</p>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                            {{ $absent->duty_date->format('l, M j, Y') }} · {{ $window['shift']->name }} from {{ $window['start']->format('g:i A') }} ·
                            {{ $absent->user->name }} {{ $absent->isNoShow() ? 'didn\'t show up' : 'can\'t report' }}
                        </p>
                    </div>
                    @if ($absent->isNoShow()) <x-ui.badge tone="red">No-show</x-ui.badge> @endif
                </div>
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ $absent->reason }}</p>
                @if ($absent->isNoShow() && $absentPhone)
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $absentPhone) }}" class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                        <x-icon name="phone" class="h-4 w-4" /> Call {{ $absent->user->name }} first: {{ $absentPhone }}
                    </a>
                @endif
            </div>

            @if ($this->holdover->isNotEmpty())
                <x-ui.alert tone="amber" class="mt-3">
                    <span class="font-medium">Still at the site:</span> {{ $this->holdover->pluck('label')->implode('; ') }}. Ask them to stay until the reliever arrives (overtime).
                </x-ui.alert>
            @endif

            <form id="assign-reliever" wire:submit="assign" class="mt-4 space-y-2">
                <fieldset>
                    <legend class="ui-label">Reliever</legend>
                    @if ($this->candidates->isEmpty())
                        <p class="mt-1 rounded-lg border border-dashed border-gray-200 px-3 py-4 text-center text-sm text-gray-500 dark:border-gray-700">Nobody is free that day.</p>
                    @else
                        <ul class="mt-1 max-h-72 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700" role="radiogroup">
                            @foreach ($this->candidates as $candidate)
                                @php
                                    [$near, $tone] = $closeness[\App\Queries\Operations\ReliefCandidatesQuery::closeness($candidate, $absent)];
                                    $phone = $candidate->employeeProfile?->contact_number;
                                @endphp
                                <li wire:key="candidate-{{ $candidate->id }}" class="flex items-center gap-3 px-3 py-2.5 has-checked:bg-blue-50 dark:has-checked:bg-blue-500/10">
                                    <input type="radio" id="candidate-{{ $candidate->id }}" wire:model="relieverId" value="{{ $candidate->id }}" class="h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500 dark:text-blue-400">
                                    <label for="candidate-{{ $candidate->id }}" class="min-w-0 flex-1 cursor-pointer">
                                        <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ $candidate->name }}</span>
                                        <span class="block truncate text-xs text-gray-500">{{ $candidate->employeeProfile?->assignmentLabel() }}</span>
                                    </label>
                                    <x-ui.badge :tone="$tone">{{ $near }}</x-ui.badge>
                                    @if ($phone)
                                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="shrink-0 rounded-md p-1.5 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-500/10 dark:text-blue-400" title="Call {{ $phone }}" aria-label="Call {{ $candidate->name }}, {{ $phone }}">
                                            <x-icon name="phone" class="h-4 w-4" />
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @error('relieverId') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </fieldset>
                <p class="text-xs text-gray-500 dark:text-gray-400">Nearest first: same site, same province, then the same region. Not listed: on leave, absent, already relieving or on duty that day, and anyone just off a shift (less than {{ config('attendance.no_show.reliever_min_rest_hours') }} hours' rest).</p>
                <x-ui.textarea label="Note to the reliever (optional)" wire:model="note" rows="2" maxlength="500" placeholder="e.g. Report to the detachment commander at 05:45." />
            </form>

            <x-slot:footer>
                <x-ui.button form="assign-reliever" wire:loading.attr="disabled" wire:target="assign">Assign and notify</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
