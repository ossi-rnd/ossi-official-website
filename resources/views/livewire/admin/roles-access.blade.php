<div class="space-y-6">
    <x-ui.card title="Who can open what" description="Gates decide access. Each page needs one gate; every action re-checks it, so removing a role takes effect on the user's very next click. Satellite Operation sees only the region set on their account.">
        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="ui-th">Gate</th>
                        @foreach ($roles as $role)
                            <th class="ui-th text-center"><x-ui.badge :tone="$role->tone()">{{ $role->label() }}</x-ui.badge></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($abilities as $ability)
                        <tr>
                            <td class="ui-td font-mono text-xs text-gray-800 dark:text-gray-100">{{ $ability->value }}</td>
                            @foreach ($roles as $role)
                                <td class="ui-td text-center">
                                    @if (in_array($role, $ability->roles(), true))
                                        <x-icon name="check" class="mx-auto h-4 w-4 text-blue-600 dark:text-blue-400" /><span class="sr-only">Allowed</span>
                                    @else
                                        <span class="text-gray-300" aria-hidden="true">—</span><span class="sr-only">Not allowed</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-slot:footer><p class="text-xs text-gray-500">Every signed-in user also gets the self-service pages: My Attendance, Leave, Overtime, Payslips and Profile.</p></x-slot:footer>
    </x-ui.card>

    <x-ui.card title="Users & roles" description="One role per person; anyone with an employee record also has the self-service pages. Changes are written to the audit log.">
        <x-slot:actions>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name or email…" aria-label="Search users" class="ui-field mt-0! sm:w-56">
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">User</th><th class="ui-th">Roles</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @foreach ($this->users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="ui-td">
                                <span class="flex items-center gap-2">
                                    <x-ui.avatar :user="$user" size="sm" />
                                    <span>
                                        <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $user->name }}</span>
                                        <span class="block text-xs text-gray-500">{{ $user->email }}</span>
                                    </span>
                                </span>
                            </td>
                            <td class="ui-td">
                                <div class="flex flex-wrap gap-1.5">
                                    {{-- "Employee" is automatic, so only the job role is shown (or "Employee" when there's none). --}}
                                    @php $shown = array_values(array_filter($user->roleList(), fn ($role) => $role !== \App\Enums\Role::Employee)) ?: $user->roleList(); @endphp
                                    @forelse ($shown as $role)
                                        <x-ui.badge :tone="$role->tone()">{{ $role->label() }}{{ $role === \App\Enums\Role::SatelliteOperations && $user->operations_region ? ' · '.$user->operations_region->label() : '' }}</x-ui.badge>
                                    @empty
                                        <span class="text-xs text-gray-400">Self-service only</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="ui-td text-right"><button type="button" wire:click="edit({{ $user->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Change roles</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-slot:footer>{{ $this->users->links() }}</x-slot:footer>
    </x-ui.card>

    {{-- Added by HR, not registered yet. Roles belong to accounts, so the one set here waits
         on the record and is given to their account when they register (SetPendingRole). --}}
    @if ($this->waiting->isNotEmpty())
        <x-ui.card title="Not registered yet" description="HR added them, but they haven't created their account. Set their role now: they get it the moment they register.">
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Employee</th><th class="ui-th">Role on registering</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($this->waiting as $profile)
                            <tr wire:key="pending-{{ $profile->id }}">
                                <td class="ui-td">
                                    <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $profile->name }}</span>
                                    <span class="block text-xs text-gray-500">{{ $profile->employee_number }} · {{ $profile->is_office_staff ? 'Office staff' : 'Field' }}{{ $profile->position ? ' · '.$profile->position->label() : '' }}</span>
                                </td>
                                <td class="ui-td">
                                    @if ($profile->pending_role)
                                        <x-ui.badge :tone="$profile->pending_role->tone()">{{ $profile->pending_role->label() }}{{ $profile->pending_region ? ' · '.$profile->pending_region->label() : '' }}</x-ui.badge>
                                    @else
                                        <span class="text-xs text-gray-400">Self-service only</span>
                                    @endif
                                </td>
                                <td class="ui-td text-right"><button type="button" wire:click="editPending({{ $profile->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Set role</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    @if ($this->editingProfile)
        <x-ui.modal :title="'Role for '.$this->editingProfile->name" close="cancel">
            @error('roles') <x-ui.alert tone="red" class="mb-4">{{ $message }}</x-ui.alert> @enderror

            <form id="pending-role-form" wire:submit="savePending" class="space-y-3">
                <x-ui.alert tone="blue">{{ $this->editingProfile->name }} hasn't registered yet. This role is given to their account when they do.</x-ui.alert>
                <fieldset class="space-y-3">
                    <legend class="sr-only">Role</legend>
                    <x-ui.radio id="pending-role-none" name="pending-job-role" label="No extra role" description="A regular employee: the self-service pages only (My Attendance, Leave, Payslips…)." wire:model.live="jobRole" value="" />
                    @foreach (\App\Livewire\Admin\RolesAccess::recordRoles() as $role)
                        <x-ui.radio :id="'pending-role-'.$role->value" name="pending-job-role" :label="$role->label()" :description="$role->description()" wire:model.live="jobRole" :value="$role->value" />
                    @endforeach
                </fieldset>

                @if ($jobRole === \App\Enums\Role::SatelliteOperations->value)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <x-ui.select label="Region covered" wire:model="region">
                            <option value="">Select region</option>
                            @foreach (\App\Enums\Region::cases() as $option)
                                <option value="{{ $option->value }}">{{ $option->fullLabel() }}</option>
                            @endforeach
                        </x-ui.select>
                        @error('region') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif
            </form>

            <x-slot:footer>
                <x-ui.button form="pending-role-form">Save role</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($this->editingUser)
        <x-ui.modal :title="'Roles for '.$this->editingUser->name" close="cancel">
            @error('roles') <x-ui.alert tone="red" class="mb-4">{{ $message }}</x-ui.alert> @enderror

            <form id="roles-form" wire:submit="save" class="space-y-3">
                @if ($previousRoles !== [])
                    <x-ui.alert tone="amber">{{ $this->editingUser->name }} has {{ count($previousRoles) }} roles now ({{ implode(', ', $previousRoles) }}). Each person has one role: saving keeps only the one you pick.</x-ui.alert>
                @endif

                {{-- One role per person. What each role can open is in the table above. --}}
                <fieldset class="space-y-3">
                    <legend class="sr-only">Role</legend>
                    <x-ui.radio id="role-none" name="job-role" label="No extra role" description="A regular employee: the self-service pages only (My Attendance, Leave, Payslips…)." wire:model.live="jobRole" value="" />
                    @foreach ($roles as $role)
                        @continue($role === \App\Enums\Role::Employee)
                        <x-ui.radio :id="'role-'.$role->value" name="job-role" :label="$role->label()" :description="$role->description()" wire:model.live="jobRole" :value="$role->value" />
                    @endforeach
                </fieldset>

                @if ($jobRole === \App\Enums\Role::SatelliteOperations->value)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <x-ui.select label="Region covered" wire:model="region">
                            <option value="">Select region</option>
                            @foreach (\App\Enums\Region::cases() as $option)
                                <option value="{{ $option->value }}">{{ $option->fullLabel() }}</option>
                            @endforeach
                        </x-ui.select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">They'll see and manage only guards deployed to clients in this region.</p>
                        @error('region') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif
            </form>

            <x-slot:footer>
                <x-ui.button form="roles-form">Save role</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
