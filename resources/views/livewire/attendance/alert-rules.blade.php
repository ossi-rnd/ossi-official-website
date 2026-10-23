<div>
    {{-- Company policy, not law: HR sets these (audited); SendAttendanceAlerts reads the same numbers. --}}
    <x-ui.card title="Alert rules" description="Who hears about a missed clock-out or a missing clock-in, and when. Guards who miss a rostered duty are alerted as no-shows to Operations.">
        @if ($this->canEdit())
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="edit"><x-icon name="pencil" class="h-4 w-4" /> Change</x-ui.button>
            </x-slot:actions>
        @endif
        <dl class="grid grid-cols-1 gap-4 px-6 py-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Not clocked out: remind the employee</dt><dd class="mt-0.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $rules['clock_out_reminder_minutes'] }} min after the shift</dd></div>
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Still not clocked out: tell their head</dt><dd class="mt-0.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $rules['clock_out_escalate_minutes'] }} min after the shift</dd></div>
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Office staff not clocked in: remind them</dt><dd class="mt-0.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $rules['office_clock_in_reminder_minutes'] }} min after grace</dd></div>
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Office work days</dt><dd class="mt-0.5 font-semibold text-gray-800 dark:text-gray-100">{{ $days }}</dd></div>
        </dl>
        <x-slot:footer><p class="text-xs text-gray-500 dark:text-gray-400">The head: Operation HQ and the region's Satellite for a guard; Operation HQ for a Satellite Operation; the President or Vice President for HR; HR for other office staff (Admin, Accounting, Operation HQ…). No alert on approved leave or a holiday.</p></x-slot:footer>
    </x-ui.card>

    @if ($editing)
        <x-ui.modal title="Change the alert rules" close="$set('editing', false)" max-width="md">
            <form id="attendance-alert-rules-form" wire:submit="save" class="space-y-4">
                <x-ui.input label="Remind the employee to clock out (minutes after the shift)" type="number" min="5" max="240" wire:model="clockOutReminder" />
                <x-ui.input label="Tell their head if still not clocked out (minutes after the shift)" type="number" min="15" max="1440" wire:model="clockOutEscalate" hint="Later than the reminder, so the employee gets the first chance." />
                <x-ui.input label="Remind office staff to clock in (minutes after their grace period)" type="number" min="5" max="240" wire:model="officeClockInReminder" />
                <fieldset>
                    <legend class="ui-label">Office work days</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (\App\Support\AttendanceAlertRules::DAYS as $number => $name)
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/50">
                                <input type="checkbox" value="{{ $number }}" wire:model="officeWorkDays" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600">
                                <span class="text-gray-700 dark:text-gray-200">{{ $name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Office staff have no roster: a day not ticked is a rest day, with no "not clocked in" alert.</p>
                    @error('officeWorkDays') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </fieldset>
            </form>
            <x-slot:footer>
                <x-ui.button form="attendance-alert-rules-form" wire:target="save">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
