<div class="space-y-6">
    <x-ui.alert tone="blue">
        <strong>How employees get an account:</strong> add them here with the email they will use, then give them their
        employee number. They register at <span class="font-mono">/register</span>; anyone not on this list is refused, and every attempt is in the Audit Log.
    </x-ui.alert>

    <x-ui.card title="Employees" description="HR's roster: assignment, employment status, payroll flags and government IDs.">
        <x-slot:actions>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, email or employee #…" aria-label="Search employees" class="ui-field mt-0! sm:w-56">
            <x-ui.select wire:model.live="statusFilter" aria-label="Employment status">
                <option value="all">All statuses</option>
                @foreach (\App\Enums\EmploymentStatus::cases() as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select wire:model.live="accountFilter" aria-label="Account">
                <option value="all">All accounts</option>
                <option value="registered">Registered</option>
                <option value="unregistered">Not registered yet</option>
            </x-ui.select>
            @can(\App\Enums\Ability::ManagePeople->value)
                <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="user-plus" class="h-4 w-4" /> Add employee</x-ui.button>
            @endcan
        </x-slot:actions>

        {{-- One search for everyone; the tabs narrow it to guards or office staff (same look as the request tabs). --}}
        @if ($this->seesBothKinds())
            @php $counts = $this->kindCounts; @endphp
            <div class="relative" x-data="{ shown: @js($kind) }">
                <div class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-gray-200 px-4 dark:border-gray-800" role="tablist" aria-label="Which employees">
                    @foreach (['all' => 'All', 'field' => 'Guards', 'office' => 'Office staff'] as $value => $label)
                        <button type="button" role="tab" wire:click="showKind('{{ $value }}')" x-on:click="shown = '{{ $value }}'"
                            aria-selected="{{ $kind === $value ? 'true' : 'false' }}" x-bind:aria-selected="(shown === '{{ $value }}').toString()"
                            class="-mb-px inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors"
                            x-bind:class="{ 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400': shown === '{{ $value }}', 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300': shown !== '{{ $value }}' }">
                            {{ $label }}
                            <span class="rounded-full bg-gray-100 px-1.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $counts[$value] }}</span>
                        </button>
                    @endforeach
                </div>
                <div wire:loading.delay.shortest wire:target="showKind" class="absolute inset-x-0 bottom-0 h-0.5 overflow-hidden" aria-hidden="true">
                    <div class="tab-loading-bar h-full w-1/3 bg-blue-500"></div>
                </div>
            </div>
        @endif

        @include('livewire.admin.partials.area-filter')

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="showKind">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Name</th><th class="ui-th">Employee No.</th><th class="ui-th">Assignment</th><th class="ui-th">Shift</th><th class="ui-th">Status</th><th class="ui-th">201</th><th class="ui-th">Account</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->employees as $profile)
                        <tr wire:key="profile-{{ $profile->id }}">
                            <td class="ui-td">
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ $profile->name }}</p>
                                <p class="text-xs text-gray-500">{{ $profile->email }}</p>
                                @if ($profile->is_test_account)
                                    <x-ui.badge tone="amber" class="mt-1">Test account</x-ui.badge>
                                @endif
                            </td>
                            <td class="ui-td font-mono text-xs">{{ $profile->employee_number }}</td>
                            <td class="ui-td">{{ $profile->rosterAssignmentLabel() }}</td>
                            <td class="ui-td">{{ $profile->shift?->name ?? 'Default' }}</td>
                            <td class="ui-td">
                                <x-ui.badge :tone="$profile->employment_status->isActive() ? 'gray' : 'red'">{{ $profile->employment_status->label() }}</x-ui.badge>
                                @if ($profile->is_office_staff) <x-ui.badge tone="indigo">Office</x-ui.badge> @endif
                                {{-- Merit and demerit points, last 12 months (only for those who may see them). --}}
                                @if ($merit = $this->meritTotals[$profile->user_id ?? 0] ?? null)
                                    <span class="mt-1 block text-xs whitespace-nowrap text-gray-500 tabular-nums dark:text-gray-400" title="Merit and demerit points, last 12 months">+{{ $merit['merits'] }} / −{{ $merit['demerits'] }} pts</span>
                                @endif
                                {{-- Still Probationary past the probation months: a warning dot, the date in gray (Art. 296). --}}
                                @if ($overdueSince = $profile->probationOverdueSince())
                                    <span class="mt-1 flex items-center gap-1.5 text-xs whitespace-nowrap text-gray-500 dark:text-gray-400" title="Past {{ config('hr.probation.months') }} months and still Probationary: decide on regularization.">
                                        <span class="size-1.5 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span>Probation ended {{ $overdueSince->format('M j') }}
                                    </span>
                                @endif
                            </td>
                            @php $required = count($profile->applicableRequirements()); $onFile = (int) $profile->requirements_on_file_count; @endphp
                            <td class="ui-td whitespace-nowrap">
                                <span @class(["font-medium", "text-emerald-600 dark:text-emerald-400" => $onFile === $required, "text-gray-600 dark:text-gray-300" => $onFile !== $required])>{{ $onFile }}/{{ $required }}</span>
                            </td>
                            <td class="ui-td">
                                @if (! $profile->isClaimed())
                                    <x-ui.badge tone="amber">Not registered</x-ui.badge>
                                @elseif ($profile->user?->email_verified_at === null)
                                    <x-ui.badge tone="blue">Email unconfirmed</x-ui.badge>
                                @else
                                    <x-ui.badge tone="green">Active</x-ui.badge>
                                @endif
                            </td>
                            <td class="ui-td text-right">
                                <a href="{{ route('employees.show', $profile) }}" wire:navigate class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="ui-td py-8 text-center">No employees match your filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>{{ $this->employees->links() }}</x-slot:footer>
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal title="Add employee" close="$set('showForm', false)" max-width="2xl">
            <form id="add-employee" wire:submit="save">
                @include('livewire.admin.partials.employee-form')
            </form>

            <x-slot:footer>
                <x-ui.button form="add-employee" wire:loading.attr="disabled" wire:target="save">Add employee</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
