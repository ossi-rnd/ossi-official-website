<div class="space-y-6">
    <x-ui.card title="Notices to Explain" description="Due process before a real sanction (the twin-notice rule): the specific acts, the rule, the possible penalty, and at least {{ \App\Models\NoticeToExplain::MIN_DAYS }} calendar days to explain in writing. A hearing if asked for or needed. Then the decision with its reasons. The employee acknowledges receiving each notice.">
        <x-slot:actions>
            <x-ui.select wire:model.live="statusFilter" aria-label="Show">
                <option value="open">Not decided yet</option>
                <option value="all">All</option>
            </x-ui.select>
            <x-ui.button type="button" wire:click="startIssuing">Issue a notice</x-ui.button>
        </x-slot:actions>

        <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-50" wire:target="statusFilter">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Employee</th><th class="ui-th">Notice</th><th class="ui-th">Issued</th><th class="ui-th">Where it stands</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->notices as $notice)
                        <tr wire:key="nte-{{ $notice->id }}">
                            <td class="ui-td">
                                <p><x-person-link :user="$notice->user" /></p>
                                <p class="text-xs text-gray-500">{{ $notice->user->employeeProfile?->assignmentLabel() ?? '—' }}</p>
                            </td>
                            <td class="ui-td">
                                <p class="text-gray-800 dark:text-gray-100">{{ $notice->subject }}</p>
                                <p class="text-xs text-gray-500">Could lead to: {{ $notice->possible_penalty->label() }}</p>
                            </td>
                            <td class="ui-td whitespace-nowrap">{{ $notice->created_at->format('M j, Y') }}<span class="block text-xs text-gray-500">{{ $notice->issuer?->name }}</span></td>
                            <td class="ui-td">
                                @if ($notice->isDecided())
                                    <x-ui.badge :tone="$notice->decision?->tone() ?? 'gray'">{{ $notice->decision?->label() }}</x-ui.badge>
                                @else
                                    <span class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                                        @if ($notice->readyToDecide())<span class="size-1.5 shrink-0 rounded-full bg-blue-500"></span>@endif
                                        {{ $notice->stage() }}
                                    </span>
                                @endif
                            </td>
                            <td class="ui-td text-right"><button type="button" wire:click="open({{ $notice->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Open</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-td py-8 text-center">{{ $statusFilter === 'open' ? 'No notice waiting.' : 'No notices yet.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>{{ $this->notices->links() }}</x-slot:footer>
    </x-ui.card>

    {{-- Issuing. --}}
    @if ($issuing)
        <x-ui.modal title="Issue a Notice to Explain" close="closeIssuing" max-width="2xl">
            <form id="issue-form" wire:submit="issue" class="space-y-4">
                <div>
                    <p class="ui-label">Employee</p>
                    @if ($person = $this->chosen)
                        <div class="mt-1 flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                            <span><span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ $person->name }}</span><span class="block text-xs text-gray-500">{{ $person->employeeProfile?->assignmentLabel() ?? '—' }}</span></span>
                            <button type="button" wire:click="$set('issueTo', null)" class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Change</button>
                        </div>
                    @else
                        <x-ui.input type="search" wire:model.live.debounce.300ms="personSearch" placeholder="Search by name or employee no." aria-label="Search the employee by name or employee number" class="mt-1" />
                        @if ($this->candidates->isNotEmpty())
                            @if (mb_strlen(trim($personSearch)) < 2)<p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Suggested, or search above:</p>@endif
                            <ul class="mt-1 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
                                @foreach ($this->candidates as $candidate)
                                    <li wire:key="candidate-{{ $candidate->id }}"><button type="button" wire:click="choose({{ $candidate->id }})" class="w-full px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/5"><span class="block text-sm text-gray-800 dark:text-gray-100">{{ $candidate->name }}</span><span class="block text-xs text-gray-500">{{ $candidate->employeeProfile?->assignmentLabel() ?? '—' }}</span></button></li>
                                @endforeach
                            </ul>
                        @elseif (mb_strlen(trim($personSearch)) >= 2)
                            <p class="mt-1 text-xs text-gray-500">No one you supervise by that name or number.</p>
                        @endif
                        @error('issueTo') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    @endif
                </div>
                <div class="grid gap-3 sm:grid-cols-[1fr_11rem] sm:items-start">
                    <x-ui.input label="Offence" wire:model="subject" placeholder="e.g. Sleeping on post" />
                    <x-ui.input label="Date it happened" type="date" wire:model="incidentDate" :max="today()->toDateString()" />
                </div>
                <x-ui.textarea label="The specific acts: what, when, where, who saw it" wire:model="details" rows="4" maxlength="3000" placeholder="e.g. On Oct 14 at about 2:30 AM, the roving supervisor found you asleep in the guard house at Gate 2, Makati CBD. Photos were taken." />
                <x-ui.textarea label="The company rule or policy it breaks" wire:model="rule" rows="2" maxlength="1000" placeholder="e.g. Code of Conduct, Section 4.2: Sleeping while on duty." />
                <div class="grid gap-3 sm:grid-cols-2 sm:items-start">
                    <x-ui.select label="It could lead to" wire:model="possiblePenalty">
                        @foreach (\App\Enums\NteOutcome::penalties() as $penalty)
                            <option value="{{ $penalty->value }}">{{ $penalty->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Explain in writing by" type="date" wire:model="explainBy" :min="today()->addDays(\App\Models\NoticeToExplain::MIN_DAYS)->toDateString()" :hint="'At least '.\App\Models\NoticeToExplain::MIN_DAYS.' calendar days.'" />
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="ghost" wire:click="closeIssuing">Cancel</x-ui.button>
                <x-ui.button form="issue-form" wire:target="issue" wire:confirm="Issue this Notice to Explain? The employee is told right away.">Issue notice</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- One notice: read it, set a hearing, decide. --}}
    @if ($notice = $this->opened)
        @php $canAct = \App\Models\NoticeToExplain::canIssue(auth()->user(), $notice->user); @endphp
        <x-ui.modal :title="$notice->user->name.' · Notice to Explain'" close="close" max-width="2xl">
            @include('livewire.partials.nte-document', ['notice' => $notice])

            @if ($canAct && ! $notice->isDecided())
                {{-- A hearing: when asked for, or needed. --}}
                <form wire:submit="scheduleHearing" class="mt-5 rounded-lg border border-dashed border-gray-300 p-4 dark:border-gray-700">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $notice->hearing_at ? 'Move the hearing' : 'Set a hearing' }} <span class="font-normal text-gray-500">{{ $notice->wants_hearing ? '· they asked for one' : '· optional' }}</span></p>
                    <div class="mt-2 grid gap-3 sm:grid-cols-[13rem_1fr_auto] sm:items-start">
                        <x-ui.input label="When" type="datetime-local" wire:model="hearingAt" />
                        <x-ui.input label="Where" wire:model="hearingPlace" placeholder="e.g. HQ conference room" />
                        <div><span class="ui-label invisible hidden sm:block" aria-hidden="true">Set</span><div class="sm:mt-1"><x-ui.button variant="secondary" wire:target="scheduleHearing">Set</x-ui.button></div></div>
                    </div>
                </form>

                {{-- The decision, once due process is done. --}}
                @if ($notice->readyToDecide())
                    <form id="decide-form" wire:submit="decide" class="mt-5 space-y-4">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">Decision</p>
                        <div class="grid gap-3 sm:grid-cols-2 sm:items-start">
                            <x-ui.select label="Decision" wire:model.live="decision">
                                @foreach (\App\Enums\NteOutcome::cases() as $outcome)
                                    <option value="{{ $outcome->value }}">{{ $outcome->label() }}</option>
                                @endforeach
                            </x-ui.select>
                            @if ($decision !== 'cleared')
                                <x-ui.select label="With a demerit (optional)" wire:model="demeritTypeId">
                                    <option value="">No demerit</option>
                                    @foreach ($this->demerits as $type)
                                        <option value="{{ $type->id }}">{{ $type->name }} (−{{ $type->points }})</option>
                                    @endforeach
                                </x-ui.select>
                            @endif
                        </div>
                        @if ($decision === 'suspension')
                            <div class="grid gap-3 sm:grid-cols-2 sm:items-start">
                                <x-ui.input label="Days (1–30)" type="number" min="1" max="30" wire:model="suspensionDays" />
                                <x-ui.input label="From" type="date" wire:model="suspensionFrom" />
                            </div>
                        @endif
                        <x-ui.textarea label="The reasons (the second notice)" wire:model="decisionReason" rows="4" maxlength="3000" placeholder="e.g. The explanation and the hearing confirmed he was asleep at 2:30 AM; it's his first offence, so a reprimand." />
                        @if ($decision === 'dismissal')<p class="-mt-2 text-xs text-gray-500">HR is told to set "Terminated" on the record and start the clearance and final pay.</p>@endif
                    </form>
                @else
                    <p class="mt-5 text-sm text-gray-500">The decision opens once they explain, or on {{ $notice->explain_by->copy()->addDay()->format('M j') }} if they don't: they must have their chance first.</p>
                @endif
            @endif

            @if ($canAct && ! $notice->isDecided() && $notice->readyToDecide())
                <x-slot:footer>
                    <x-ui.button type="button" variant="ghost" wire:click="close">Close</x-ui.button>
                    <x-ui.button form="decide-form" wire:target="decide" wire:confirm="Send this decision? The employee is told right away, and it can't be changed.">Send decision</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.modal>
    @endif
</div>
