<div>
    <x-ui.card title="Leave Requests" :description="$this->pendingCount.' pending. Guards\' leave goes Satellite Operation → Operation HQ → HR (final). Office staff\'s goes to their head: HR, Operation HQ for a Satellite, the President or VP for HR.'">
        <x-slot:actions>
            <x-ui.select wire:model.live="statusFilter" aria-label="Filter by status">
                <option value="pending">Pending</option>
                <option value="mine">Waiting for me</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="cancelled">Cancelled</option>
                <option value="all">All</option>
            </x-ui.select>
        </x-slot:actions>

        @include('livewire.admin.partials.staff-kind-tabs')

        @error('list') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        {{-- Dimmed while another tab or status loads (the tabs show a bar meanwhile). --}}
        <div class="overflow-x-auto transition-opacity" wire:loading.class="pointer-events-none opacity-50" wire:target="showGroup,statusFilter">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Employee</th><th class="ui-th">Filed</th><th class="ui-th">Type</th><th class="ui-th">Dates</th><th class="ui-th">Reason</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->requests as $request)
                        @php
                            $pending = $request->status === \App\Enums\RequestStatus::Pending;
                            $endorsesAs = $pending ? \App\Actions\Leave\EndorseLeaveRequest::endorsesAs($request, auth()->user()) : null;
                            $blocked = $pending ? \App\Actions\Leave\ReviewLeaveRequest::blockedReason($request, auth()->user()) : null;
                        @endphp
                        <tr wire:key="leave-{{ $request->id }}">
                            <td class="ui-td">
                                <p><x-person-link :user="$request->user" /></p>
                                <p class="text-xs text-gray-500">{{ $request->user->employeeProfile?->assignmentLabel() ?? $request->user->email }}</p>
                            </td>
                            <td class="ui-td"><x-filed-at :at="$request->created_at" /></td>
                            <td class="ui-td">
                                {{ $request->type->label() }}
                                @if ($request->documentNeeded())
                                    <p class="text-xs text-gray-500">{{ $request->documents->isEmpty() ? 'Document needed' : 'Document attached' }}</p>
                                @endif
                            </td>
                            <td class="ui-td whitespace-nowrap">{{ $request->dateRangeLabel() }}</td>
                            <td class="ui-td max-w-xs truncate" title="{{ $request->reason }}">{{ $request->reason }}</td>
                            <td class="ui-td">
                                @if ($request->waitingLabel())
                                    <x-ui.badge tone="amber">{{ $request->waitingLabel() }}</x-ui.badge>
                                @else
                                    <x-ui.status-badge :status="$request->status" />
                                @endif
                                {{-- The way up so far. The verdict as a word, no icon (it only repeated the text);
                                     red on "Not recommended" alone, the one thing the decider must notice. --}}
                                @foreach ($request->endorsements as $endorsement)
                                    <div class="mt-1.5 text-xs leading-tight" title="{{ $endorsement->note }}">
                                        <span class="block whitespace-nowrap text-gray-700 dark:text-gray-300">{{ $endorsement->stage->label() }} · {{ $endorsement->endorser->name }}</span>
                                        <span class="block whitespace-nowrap text-gray-400 dark:text-gray-500">
                                            @if ($endorsement->recommends)
                                                Endorsed
                                            @else
                                                <span class="font-medium text-red-600 dark:text-red-400">Not recommended</span>
                                            @endif
                                            · {{ $endorsement->created_at->format('M j, g:i A') }}
                                        </span>
                                    </div>
                                @endforeach
                            </td>
                            <td class="ui-td text-right">
                                @if ($endorsesAs)
                                    <button type="button" wire:click="startEndorse({{ $request->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Endorse</button>
                                @elseif ($pending && $blocked === null)
                                    <button type="button" wire:click="startReview({{ $request->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Review</button>
                                @elseif ($pending && $request->user_id !== auth()->id() && ! \App\Models\LeaveRequest::canDecideFor(auth()->user(), $request->user))
                                    {{-- Not theirs to decide (e.g. the President or Vice President watching others'): the status says where it is. --}}
                                    <span class="text-xs text-gray-400">View only</span>
                                @elseif ($pending)
                                    <span class="text-xs text-gray-500">{{ $blocked }}</span>
                                @else
                                    <span class="text-xs text-gray-400">{{ $request->reviewer?->name ?? '—' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-td py-8 text-center">{{ $statusFilter === 'mine' ? 'Nothing is waiting for you.' : ($this->hasGroups() ? ($this->shownGroup() === 'office' ? 'No office staff leave here.' : 'No guards\' leave here.') : 'No leave requests here.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>{{ $this->requests->links() }}</x-slot:footer>
    </x-ui.card>

    @if ($this->endorsing)
        @php $as = \App\Actions\Leave\EndorseLeaveRequest::endorsesAs($this->endorsing, auth()->user()); @endphp
        <x-ui.modal title="Endorse leave request" close="cancelEndorse">
            @include('livewire.admin.partials.leave-summary', ['leave' => $this->endorsing])

            @error('endorse') <x-ui.alert tone="red" class="mt-4">{{ $message }}</x-ui.alert> @enderror

            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                As {{ $as?->label() }}, you pass it to <b>{{ $as === \App\Enums\LeaveStage::Satellite ? 'Operation HQ' : 'HR' }}</b>{{ $as === \App\Enums\LeaveStage::Satellite ? '' : ', who decides' }}.
                @if ($as === \App\Enums\LeaveStage::OperationsHq && $this->endorsing->stage === \App\Enums\LeaveStage::Satellite)
                    <span class="text-amber-700 dark:text-amber-400">The Satellite Operation hasn't endorsed it yet; yours goes straight to HR.</span>
                @endif
            </p>

            <fieldset class="mt-3">
                <legend class="sr-only">Your recommendation</legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ([[true, 'Endorse'], [false, 'Not recommended']] as [$value, $label])
                        <label class="cursor-pointer">
                            <input type="radio" wire:model.live="endorseRecommends" value="{{ $value ? '1' : '0' }}" class="peer sr-only">
                            <span @class([
                                'block rounded-lg border px-3 py-2 text-center text-sm font-medium peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500',
                                'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' => $endorseRecommends === $value,
                                'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200' => $endorseRecommends !== $value,
                            ])>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="mt-4">
                <x-ui.textarea :label="$endorseRecommends ? 'Note for the next step (optional)' : 'Why not? (required, HR sees it)'" wire:model="endorseNote" rows="3" maxlength="1000"
                    :placeholder="$endorseRecommends ? 'e.g. Reliever already lined up for Oct 12' : 'e.g. Two guards already on leave at this post that week'" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" wire:click="cancelEndorse">Cancel</x-ui.button>
                <x-ui.button type="button" wire:click="endorse" wire:loading.attr="disabled">{{ $as === \App\Enums\LeaveStage::Satellite ? 'Send to Operation HQ' : 'Send to HR' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- The leave rules, company policy: HR sets them here (audited); My Leave shows the same numbers. --}}
    @php $rules = \App\Support\LeaveRules::all(); @endphp
    <x-ui.card class="mt-6" title="Rules" description="Company policy, not law. My Leave shows these same numbers, and every change is in the audit log.">
        @if ($this->canEditRules())
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="editRules">Change</x-ui.button>
            </x-slot:actions>
        @endif
        <dl class="grid grid-cols-1 gap-4 px-6 py-5 text-sm sm:grid-cols-2">
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Medical certificate for sick leave from</dt><dd class="mt-0.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $rules['sick_certificate_from_days'] }} {{ str('day')->plural($rules['sick_certificate_from_days']) }}</dd></div>
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Sick or emergency leave filed up to</dt><dd class="mt-0.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $rules['file_after_days'] }} {{ str('day')->plural($rules['file_after_days']) }} after</dd></div>
        </dl>
    </x-ui.card>

    @if ($editingRules)
        <x-ui.modal title="Change the leave rules" close="$set('editingRules', false)" max-width="md">
            <form id="leave-rules-form" wire:submit="saveRules" class="space-y-4">
                <x-ui.input label="Medical certificate for sick leave from (days)" type="number" min="1" max="30" wire:model="sickCertificateFromDays" hint="Sick leave this long or longer needs one before it's approved. 2 is common." />
                <x-ui.input label="Sick or emergency leave can be filed up to (days after)" type="number" min="0" max="30" wire:model="fileAfterDays" hint="People are sick first and file once back. 0: only from today on." />
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="ghost" wire:click="$set('editingRules', false)">Cancel</x-ui.button>
                <x-ui.button form="leave-rules-form" wire:target="saveRules">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($this->reviewing)
        <x-ui.modal title="Review leave request" close="cancelReview">
            @include('livewire.admin.partials.leave-summary', ['leave' => $this->reviewing])

            @error('review') <x-ui.alert tone="red" class="mt-4">{{ $message }}</x-ui.alert> @enderror
            {{-- No approving without the proof it needs; rejecting is still possible. --}}
            @php $approvalBlocked = \App\Actions\Leave\ReviewLeaveRequest::approvalBlockedReason($this->reviewing); @endphp
            @if ($approvalBlocked) <x-ui.alert tone="amber" class="mt-4">{{ $approvalBlocked }}</x-ui.alert> @endif

            <div class="mt-4">
                <x-ui.textarea label="Note to the employee (optional)" wire:model="reviewNote" rows="3" maxlength="1000" />
            </div>

            <x-slot:footer>
                {{-- The two decisions as equal halves: balanced, big targets, each clearly on its own side. --}}
                <div class="grid w-full grid-cols-2 gap-3">
                    <x-ui.button variant="secondary" type="button" wire:click="reject" wire:loading.attr="disabled" class="w-full">Reject</x-ui.button>
                    <x-ui.button variant="primary" type="button" wire:click="approve" wire:loading.attr="disabled" class="w-full" :disabled-reason="$approvalBlocked">Approve</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
