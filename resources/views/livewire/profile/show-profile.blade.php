<div class="mx-auto max-w-3xl space-y-6">
    <div class="ui-card p-6 sm:p-8">
        <div class="flex flex-col items-center gap-5 sm:flex-row">
            <x-ui.avatar :user="$user" size="lg" />
            <div class="min-w-0 flex-1 text-center sm:text-left">
                <div class="flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                    <p class="text-xl font-semibold text-gray-800 dark:text-gray-100">{{ $user->name }}</p>
                    @foreach ($user->roleList() as $role)
                        <x-ui.badge :tone="$role->tone()">{{ $role->label() }}</x-ui.badge>
                    @endforeach
                </div>
                <p class="mt-0.5 text-sm text-gray-500">{{ $user->email }}</p>
                <p class="mt-1 text-xs text-gray-400">Member since {{ $user->created_at->format('F Y') }} · {{ $shift->label() }}</p>
            </div>
            <x-ui.button variant="secondary" :href="route('account-settings')" wire:navigate>Account settings</x-ui.button>
        </div>
    </div>

    @if ($profile === null && auth()->user()?->isPaidOutsidePayroll())
        {{-- Super Admin, President, Vice President: accounts without an employee record, by design. --}}
        @php $accountRole = collect(auth()->user()->roleList())->first(fn ($role) => in_array($role, \App\Enums\Role::withoutEmployeeRecord(), true)); @endphp
        <x-ui.alert tone="blue">
            This is a {{ $accountRole?->label() }} account, not an employee: it has no employee number, and isn't paid or clocked in through the system.
            {{ $accountRole === \App\Enums\Role::SuperAdmin ? 'It can still open and manage every part of the system.' : 'It still has all its '.$accountRole?->label().' access and approvals.' }}
        </x-ui.alert>
    @elseif ($profile === null)
        <x-ui.alert tone="amber">HR hasn't set up your employee record yet, so your position, employment status and government IDs aren't on file.</x-ui.alert>
    @else
        @php
            $sections = [
                // Detachment and post are where a guard is deployed; office staff have neither.
                'Employment' => [
                    'Employee number' => $profile->employee_number,
                    'Position' => $profile->position?->label(),
                    ...($profile->hasDeployment() ? ['Detachment' => $profile->detachment, 'Post' => $profile->post] : []),
                    ...($profile->is_office_staff && $profile->office ? ['Office' => $profile->office->name] : []),
                    'Hire date' => $profile->hire_date?->format('M j, Y'),
                    'Employment status' => $profile->employment_status->label(),
                    'Staff type' => $profile->is_office_staff ? 'Office staff' : 'Field staff',
                ],
                'Contact on file' => [
                    'Email on HR record' => $profile->email,
                    'Contact number' => $profile->contact_number,
                    'Permanent address' => $profile->permanent_address,
                    'Date of birth' => $profile->birthDate()?->format('M j, Y'),
                    'Emergency contact' => trim($profile->emergency_contact_name.' '.$profile->emergency_contact_phone),
                ],
                'Government IDs' => [
                    'SSS' => $profile->sss_number,
                    'PhilHealth' => $profile->philhealth_number,
                    'Pag-IBIG' => $profile->pagibig_number,
                    'TIN' => $profile->tin_number,
                    // Other IDs HR added (driver's license, passport…), under their own names.
                    ...collect($profile->other_ids ?? [])->mapWithKeys(fn (array $id) => [$id['label'] => $id['number']])->all(),
                ],
            ];
        @endphp

        @foreach ($sections as $heading => $fields)
            <x-ui.card :title="$heading" :description="$loop->first ? 'Set by HR. Contact HR if anything here is wrong.' : null">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 p-4 sm:grid-cols-2">
                    @foreach ($fields as $label => $value)
                        <div>
                            <dt class="text-xs font-medium tracking-wide text-gray-400 uppercase">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm text-gray-800 dark:text-gray-100">{{ filled($value) ? $value : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>
        @endforeach

        <x-sensitive>
            <livewire:people.requirements-checklist :profile-id="$profile->id" :key="'requirements-'.$profile->id" />
        </x-sensitive>

        {{-- Their merits and demerits, and their side of a demerit. --}}
        <livewire:people.merit-history :user-id="auth()->id()" :key="'merits-'.auth()->id()" />

        {{-- Leaving: a quiet line at the end, not a daily menu item (it shows in the menu once filed). --}}
        @if (! auth()->user()?->isPaidOutsidePayroll())
            <p class="text-center text-sm text-gray-500 dark:text-gray-400">
                Leaving the company? <a href="{{ route('resignation') }}" wire:navigate class="font-medium text-gray-700 underline decoration-gray-300 underline-offset-4 hover:text-blue-600 dark:text-gray-300 dark:decoration-gray-600 dark:hover:text-blue-400">File your resignation</a>
            </p>
        @endif
    @endif
</div>
