@php $duration = fn (?int $minutes) => $minutes === null ? '—' : intdiv($minutes, 60).'h '.($minutes % 60).'m'; @endphp

<div>
    <x-ui.card title="Overtime Requests" :description="$this->pendingCount.' pending review. Only approved overtime is paid, up to the approved hours.'">
        <x-slot:actions>
            {{-- Extra time Operations knows about (a guard kept on at the post): no need for the guard to file it. --}}
            @if ($this->canAddExtra())
                <x-ui.button type="button" variant="dark" size="sm" wire:click="startExtra"><x-icon name="clock-plus" class="h-4 w-4" /> Add extra time</x-ui.button>
            @endif
            <x-ui.select wire:model.live="statusFilter" aria-label="Filter by status">
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="all">All</option>
            </x-ui.select>
        </x-slot:actions>

        @include('livewire.admin.partials.staff-kind-tabs')

        @error('list') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        {{-- Dimmed while another tab or status loads (the tabs show a bar meanwhile). --}}
        <div class="overflow-x-auto transition-opacity" wire:loading.class="pointer-events-none opacity-50" wire:target="showGroup,statusFilter">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Employee</th><th class="ui-th">Filed</th><th class="ui-th">Post</th><th class="ui-th">Sent to</th><th class="ui-th">Date</th><th class="ui-th">Requested</th><th class="ui-th">Approved</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->requests as $request)
                        <tr wire:key="ot-{{ $request->id }}">
                            <td class="ui-td"><x-person-link :user="$request->user" /></td>
                            <td class="ui-td"><x-filed-at :at="$request->created_at" /></td>
                            <td class="ui-td">{{ $request->post ?? '—' }}</td>
                            <td class="ui-td">{{ $request->sentToLabel() }}</td>
                            <td class="ui-td whitespace-nowrap">{{ $request->date->format('M j, Y') }}</td>
                            <td class="ui-td">{{ $duration($request->requested_minutes) }}</td>
                            <td class="ui-td">{{ $duration($request->approved_minutes) }}</td>
                            <td class="ui-td"><x-ui.status-badge :status="$request->status" /></td>
                            <td class="ui-td text-right">
                                @if ($request->status === \App\Enums\RequestStatus::Pending)
                                    @if (($blocked = \App\Actions\Overtime\ReviewOvertimeRequest::blockedReason($request, auth()->user())) === null)
                                        <button type="button" wire:click="startReview({{ $request->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Review</button>
                                    @elseif (! auth()->user()->can(\App\Models\OvertimeRequest::reviewAbilityFor($request->user)->value))
                                        <span class="text-xs text-gray-400">View only</span>
                                    @else
                                        <span class="text-xs text-gray-500">{{ $blocked }}</span>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">{{ $request->reviewer?->name ?? '—' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="ui-td py-8 text-center">No overtime requests here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>{{ $this->requests->links() }}</x-slot:footer>
    </x-ui.card>

    @if ($addingExtra)
        <x-ui.modal title="Add extra time for a guard" close="cancelExtra">
            <p class="text-sm text-gray-600 dark:text-gray-300">For time a guard worked past their duty that you know about, like holding the post until the next guard came. It's saved as approved and the guard is told. Payroll pays it only up to the time they actually clocked.</p>

            @error('extra') <x-ui.alert tone="red" class="mt-4">{{ $message }}</x-ui.alert> @enderror

            <form id="add-extra" wire:submit="recordExtra" class="mt-4 space-y-4">
                <x-ui.select label="Guard" wire:model="extraGuardId" searchable>
                    <option value="">Select guard</option>
                    @foreach ($this->guards as $guard)
                        <option value="{{ $guard->id }}">{{ $guard->name }}{{ $guard->employeeProfile?->hasDeployment() && $guard->employeeProfile->assignmentLabel() !== '—' ? ' · '.$guard->employeeProfile->assignmentLabel() : '' }}</option>
                    @endforeach
                </x-ui.select>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input label="Date worked" type="date" wire:model="extraDate" max="{{ today()->toDateString() }}" />
                    <x-ui.input label="Extra hours" type="number" step="0.25" min="0.25" max="12" wire:model="extraHours" hint="e.g. 1.5 for 1 hour 30 minutes" />
                </div>
                <x-ui.textarea label="Reason" wire:model="extraReason" rows="3" maxlength="1000" placeholder="e.g. Held the post until 10 PM: the night guard arrived late." />
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" wire:click="cancelExtra">Cancel</x-ui.button>
                <x-ui.button form="add-extra" wire:loading.attr="disabled" wire:target="recordExtra">Add extra time</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($this->reviewing)
        <x-ui.modal title="Review overtime request" close="cancelReview">
            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-gray-800/50">
                <div class="flex items-start justify-between gap-3">
                    <p><x-person-link :user="$this->reviewing->user" /></p>
                    <x-ui.badge>{{ $duration($this->reviewing->requested_minutes) }} requested</x-ui.badge>
                </div>
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ $this->reviewing->date->format('l, M j, Y') }} · Post: {{ $this->reviewing->post ?? '—' }} · Sent to: {{ $this->reviewing->sentToLabel() }}</p>
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ $this->reviewing->reason }}</p>
            </div>

            @error('review') <x-ui.alert tone="red" class="mt-4">{{ $message }}</x-ui.alert> @enderror

            <div class="mt-4 space-y-4">
                <x-ui.input label="Approved hours" type="number" step="0.25" min="0.25" max="12" wire:model="approvedHours" hint="Can be less than requested. Payroll pays the smaller of this and the logged overtime." />
                <x-ui.textarea label="Note to the employee (optional)" wire:model="reviewNote" rows="3" maxlength="1000" />
            </div>

            <x-slot:footer>
                {{-- The two decisions as equal halves: balanced, big targets, each clearly on its own side. --}}
                <div class="grid w-full grid-cols-2 gap-3">
                    <x-ui.button variant="secondary" type="button" wire:click="reject" wire:loading.attr="disabled" class="w-full">Reject</x-ui.button>
                    <x-ui.button variant="primary" type="button" wire:click="approve" wire:loading.attr="disabled" class="w-full">Approve</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
