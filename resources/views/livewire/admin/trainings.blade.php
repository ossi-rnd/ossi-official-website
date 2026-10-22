<div class="space-y-6">
    {{-- Guards ask on My Training; answered here (added to a session, or declined with a reason). --}}
    @if ($this->requests->isNotEmpty())
        @php $pendingRequests = $this->requests->where('status', \App\Enums\RequestStatus::Pending)->count(); @endphp
        <x-ui.card title="Training requests" :description="$pendingRequests.' to answer. Approving adds the guard to a session, or keeps them waiting until you add them to one.'">
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Guard</th><th class="ui-th">Filed</th><th class="ui-th">Training</th><th class="ui-th">Why</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($this->requests as $request)
                            <tr wire:key="request-{{ $request->id }}">
                                <td class="ui-td">
                                    <x-person-link :user="$request->user" />
                                    <p class="text-xs text-gray-500">{{ $request->user->employeeProfile?->assignmentLabel() }}</p>
                                </td>
                                <td class="ui-td"><x-filed-at :at="$request->created_at" /></td>
                                <td class="ui-td">
                                    <p class="text-gray-800 dark:text-gray-100">{{ $request->kind->label() }}</p>
                                    @if ($request->preferred_from) <p class="text-xs text-gray-500">From {{ $request->preferred_from->format('M j, Y') }}</p> @endif
                                </td>
                                <td class="ui-td max-w-xs"><p class="line-clamp-2">{{ $request->reason }}</p></td>
                                <td class="ui-td whitespace-nowrap">{{ $request->isWaitingForSession() ? 'Waiting for a session' : 'Pending' }}</td>
                                <td class="ui-td text-right">
                                    @if ($request->status === \App\Enums\RequestStatus::Pending)
                                        <button type="button" wire:click="answer({{ $request->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Answer</button>
                                    @else
                                        <span class="text-xs text-gray-500">Add them under Attendees</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card title="Guards' training" description="Pre-licensing, refreshers, firearms, first aid. Mark who completed each one: it shows on their employee record.">
        <x-slot:actions>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="create"><x-icon name="plus" class="h-4 w-4" /> New training</x-ui.button>
        </x-slot:actions>

        @error('training') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Training</th><th class="ui-th">Dates</th><th class="ui-th">Venue</th><th class="ui-th">Certificate valid until</th><th class="ui-th">Attendees</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->trainings as $training)
                        @php $past = ($training->ends_on ?? $training->starts_on)->lt(today()); @endphp
                        <tr wire:key="training-{{ $training->id }}">
                            <td class="ui-td">
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ $training->title }}</p>
                                <p class="text-xs text-gray-500">{{ $training->provider ?? '—' }}</p>
                            </td>
                            <td class="ui-td whitespace-nowrap">
                                {{ $training->dateLabel() }}
                                @unless ($past) <x-ui.badge tone="blue" class="ml-1">Upcoming</x-ui.badge> @endunless
                            </td>
                            <td class="ui-td">{{ $training->venue ?? '—' }}</td>
                            <td class="ui-td whitespace-nowrap">{{ $training->valid_until?->format('M j, Y') ?? '—' }}</td>
                            <td class="ui-td tabular-nums">{{ $training->completed_count }} / {{ $training->attendees_count }} completed</td>
                            <td class="ui-td text-right whitespace-nowrap">
                                <button type="button" wire:click="manage({{ $training->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Attendees</button>
                                <button type="button" wire:click="edit({{ $training->id }})" class="ml-3 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Edit</button>
                                <x-ui.row-delete wire:click="delete({{ $training->id }})" :disabled-reason="$training->completed_count > 0 ? 'Guards completed this training, so it stays on their records.' : null" wire:confirm="Delete &quot;{{ $training->title }}&quot;?">Delete</x-ui.row-delete>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-td py-8 text-center">No trainings yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($formOpen)
        <x-ui.modal :title="$editingId ? 'Edit training' : 'New training'" close="cancel" max-width="lg">
            <form id="training-form" wire:submit="save" class="space-y-4">
                <x-ui.input label="Title" wire:model="title" placeholder="e.g. Annual firearms refresher" />
                <x-ui.input label="Provider (optional)" wire:model="provider" placeholder="e.g. PNP-SOSIA accredited training center" />
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input label="First day" type="date" wire:model="startsOn" />
                    <x-ui.input label="Last day (optional)" type="date" wire:model="endsOn" />
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input label="Venue (optional)" wire:model="venue" />
                    <x-ui.input label="Certificate valid until (optional)" type="date" wire:model="validUntil" />
                </div>
                <x-ui.textarea label="Notes (optional)" wire:model="notes" rows="2" maxlength="2000" />
            </form>
            <x-slot:footer>
                <x-ui.button form="training-form">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($this->managing)
        <x-ui.modal :title="$this->managing->title" close="closeManage" max-width="2xl">
            <p class="text-sm text-gray-500">{{ $this->managing->dateLabel() }}{{ $this->managing->venue ? ' · '.$this->managing->venue : '' }}</p>

            <form wire:submit="enroll" class="mt-4 flex items-end gap-3">
                <div class="min-w-0 flex-1" wire:key="enroll-{{ $this->managing->attendees->count() }}">
                    <x-ui.select label="Add a guard" wire:model="enrollProfileId">
                        <option value="">Choose a guard</option>
                        @foreach ($this->enrollable as $profile)
                            <option value="{{ $profile->id }}">{{ $profile->name }} · {{ $profile->employee_number }}{{ $profile->detachment ? ' · '.$profile->detachment : '' }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.button variant="secondary" wire:loading.attr="disabled" wire:target="enroll">Add</x-ui.button>
            </form>

            @error('attendance') <x-ui.alert tone="red" class="mt-4">{{ $message }}</x-ui.alert> @enderror

            <div class="mt-4 overflow-x-auto rounded-md border border-gray-200 dark:border-gray-800">
                <table class="ui-table">
                    <thead><tr><th class="ui-th">Guard</th><th class="ui-th">Result</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($this->managing->attendees as $attendee)
                            <tr wire:key="attendee-{{ $attendee->id }}">
                                <td class="ui-td">
                                    <p class="font-medium text-gray-800 dark:text-gray-100">{{ $attendee->profile->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $attendee->profile->employee_number }}</p>
                                </td>
                                <td class="ui-td">
                                    <div class="flex flex-wrap gap-1" role="group" aria-label="Result for {{ $attendee->profile->name }}">
                                        @foreach (\App\Enums\TrainingAttendance::cases() as $option)
                                            <button type="button" wire:click="mark({{ $attendee->id }}, '{{ $option->value }}')" aria-pressed="{{ $attendee->status === $option ? 'true' : 'false' }}"
                                                @class(['rounded-md border px-2 py-1 text-xs font-medium', 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' => $attendee->status === $option, 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800' => $attendee->status !== $option])>{{ $option->label() }}</button>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="ui-td text-right">
                                    <x-ui.row-delete wire:click="remove({{ $attendee->id }})">Remove</x-ui.row-delete>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="ui-td py-6 text-center">No one enrolled yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.modal>
    @endif

    @if ($this->answering)
        <x-ui.modal title="Training request" close="closeAnswer">
            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-gray-800/50">
                <div class="flex items-start justify-between gap-3">
                    <p><x-person-link :user="$this->answering->user" /></p>
                    <x-ui.badge>{{ $this->answering->kind->label() }}</x-ui.badge>
                </div>
                <p class="mt-1 text-xs text-gray-500">{{ $this->answering->user->employeeProfile?->assignmentLabel() }}{{ $this->answering->preferred_from ? ' · prefers from '.$this->answering->preferred_from->format('M j, Y') : '' }}</p>
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ $this->answering->reason }}</p>
            </div>

            @error('review') <x-ui.alert tone="red" class="mt-4">{{ $message }}</x-ui.alert> @enderror

            <div class="mt-4 space-y-4">
                <x-ui.select label="Add to session" wire:model="sessionId">
                    <option value="">No session yet: approve, add them later</option>
                    @foreach ($this->upcoming as $session)
                        <option value="{{ $session->id }}">{{ $session->title }} · {{ $session->dateLabel() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.textarea label="Note to the guard" wire:model="reviewNote" rows="3" maxlength="1000" hint="Needed when you decline." />
            </div>

            <x-slot:footer>
                <div class="grid w-full grid-cols-2 gap-3">
                    <x-ui.button variant="secondary" type="button" wire:click="declineRequest" wire:loading.attr="disabled" class="w-full">Decline</x-ui.button>
                    <x-ui.button variant="primary" type="button" wire:click="approveRequest" wire:loading.attr="disabled" class="w-full">Approve</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
