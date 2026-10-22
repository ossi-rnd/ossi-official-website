<div>
    <x-ui.card title="Complaints" :description="(auth()->user()?->can(\App\Enums\Ability::HandleComplaints->value) ? 'Confidential: only HR sees these (complaints filed by HR staff go to the President or Vice President).' : 'Confidential: complaints filed by HR staff, which HR never sees.').' Closing a case needs the outcome in writing; the employee reads it on My Complaints.'">
        <x-slot:actions>
            <x-ui.select wire:model.live="statusFilter" aria-label="Filter by status">
                <option value="active">Open and investigating</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
                <option value="all">All</option>
            </x-ui.select>
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Case</th><th class="ui-th">Filed by</th><th class="ui-th">About</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->complaints as $complaint)
                        <tr wire:key="complaint-{{ $complaint->id }}">
                            <td class="ui-td">
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ $complaint->subject }}</p>
                                <p class="text-xs text-gray-500">{{ $complaint->reference() }} · <x-filed-at :at="$complaint->created_at" /></p>
                            </td>
                            <td class="ui-td">
                                <p><x-person-link :user="$complaint->user" /></p>
                                <p class="text-xs text-gray-500">{{ $complaint->user->employeeProfile?->assignmentLabel() ?? $complaint->user->email }}</p>
                            </td>
                            <td class="ui-td">{{ $complaint->category->label() }}</td>
                            <td class="ui-td">
                                <span class="flex flex-wrap gap-1.5">
                                    @if ($complaint->needsHelpNow()) <x-ui.badge tone="red">Asks for help</x-ui.badge> @endif
                                    <x-ui.badge :tone="$complaint->status->tone()">{{ $complaint->status->label() }}</x-ui.badge>
                                </span>
                                @if ($complaint->handler) <p class="mt-1 text-xs text-gray-500">{{ $complaint->handler->name }}</p> @endif
                            </td>
                            <td class="ui-td text-right"><button type="button" wire:click="open({{ $complaint->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Open</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-td py-8 text-center">No complaints here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>{{ $this->complaints->links() }}</x-slot:footer>
    </x-ui.card>

    {{-- The weekly limit, company policy: HR sets it here (audited); My Complaints shows the same number. --}}
    @php $perWeekNow = \App\Support\ComplaintRules::perWeek(); @endphp
    <x-ui.card class="mt-6" title="Rules" description="Company policy, not law. My Complaints shows the same number, and every change is in the audit log.">
        @if ($this->canEditRules())
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="editRules">Change</x-ui.button>
            </x-slot:actions>
        @endif
        <dl class="grid grid-cols-1 gap-4 px-6 py-5 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Common subjects</dt>
                <dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $this->subjectList->where('is_active', true)->count() }} to pick from @if ($this->canEditRules())<button type="button" wire:click="openSubjects" class="ml-1 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Manage</button>@endif</dd>
            </div>
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Complaints one employee can file</dt><dd class="mt-0.5 font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $perWeekNow === 0 ? 'No limit' : $perWeekNow.' in 7 days' }}</dd></div>
            <div><dt class="text-xs text-gray-500 dark:text-gray-400">Never limited</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100">Asking for help, harassment, and cases opened by a resignation</dd></div>
        </dl>
    </x-ui.card>

    @if ($managingSubjects)
        <x-ui.modal title="Common complaint subjects" close="closeSubjects" max-width="2xl">
            <p class="text-sm text-gray-600 dark:text-gray-300">Employees pick one of these under the category, or "Other" to type their own. A complaint keeps the words it was filed with, so renaming or switching one off never changes past cases. The number is how many came in with it in the last 90 days.</p>

            <form id="subject-form" wire:submit="saveSubject" class="mt-4 grid grid-cols-1 items-end gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-[12rem_1fr_auto] dark:border-gray-700">
                <x-ui.select label="Category" wire:model="subjectCategory">
                    <option value="">Choose one</option>
                    @foreach (\App\Enums\ComplaintCategory::cases() as $option)
                        @continue($option === \App\Enums\ComplaintCategory::Other)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input :label="$subjectEditingId ? 'Rename to' : 'New subject'" wire:model="subjectLabel" maxlength="150" placeholder="e.g. Night differential not paid" />
                <div class="flex gap-2">
                    @if ($subjectEditingId)
                        <x-ui.button type="button" variant="ghost" wire:click="$set('subjectEditingId', null)">Cancel</x-ui.button>
                    @endif
                    <x-ui.button form="subject-form" variant="secondary" wire:target="saveSubject">{{ $subjectEditingId ? 'Save' : 'Add' }}</x-ui.button>
                </div>
            </form>

            <div class="mt-4 space-y-4">
                @foreach ($this->subjectList->groupBy(fn ($subject) => $subject->category->value) as $categoryValue => $subjects)
                    <div wire:key="subjects-{{ $categoryValue }}">
                        <p class="text-xs font-medium tracking-wide text-gray-400 uppercase">{{ \App\Enums\ComplaintCategory::from($categoryValue)->label() }}</p>
                        <ul class="mt-1 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
                            @foreach ($subjects as $subject)
                                <li wire:key="subject-{{ $subject->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                    <span @class(['min-w-0 truncate', 'text-gray-800 dark:text-gray-100' => $subject->is_active, 'text-gray-400 line-through' => ! $subject->is_active])>{{ $subject->label }}</span>
                                    <span class="flex shrink-0 items-center gap-3 text-xs">
                                        <span class="text-gray-500 tabular-nums" title="Complaints with this subject in the last 90 days">{{ $subject->recent_count }}</span>
                                        <button type="button" wire:click="editSubject({{ $subject->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Rename</button>
                                        <button type="button" wire:click="toggleSubject({{ $subject->id }})" class="font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100">{{ $subject->is_active ? 'Switch off' : 'Switch on' }}</button>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </x-ui.modal>
    @endif

    @if ($editingRules)
        <x-ui.modal title="Change the complaint rules" close="$set('editingRules', false)" max-width="md">
            <form id="complaint-rules-form" wire:submit="saveRules" class="space-y-4">
                <x-ui.input label="Complaints one employee can file in 7 days" type="number" min="0" max="10" wire:model="perWeek" hint="0: no limit. Asking for help and harassment are never limited." />
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="ghost" wire:click="$set('editingRules', false)">Cancel</x-ui.button>
                <x-ui.button form="complaint-rules-form" wire:target="saveRules">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($this->opened)
        <x-ui.modal :title="$this->opened->reference().' · '.$this->opened->subject" close="close" max-width="2xl">
            @if ($this->opened->needsHelpNow())
                <x-ui.alert tone="red" class="mb-4"><strong>{{ $this->opened->user->name }} asked for help now.</strong> Reach them as soon as you can{{ $this->opened->user->employeeProfile?->contact_number ? ' ('.$this->opened->user->employeeProfile->contact_number.')' : '' }}, then move the case to Investigating.</x-ui.alert>
            @endif
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs font-medium tracking-wide text-gray-400 uppercase">Filed by</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $this->opened->user->name }} · {{ $this->opened->created_at->format('M j, Y g:i A') }}</dd></div>
                <div><dt class="text-xs font-medium tracking-wide text-gray-400 uppercase">About</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $this->opened->category->label() }}</dd></div>
                <div><dt class="text-xs font-medium tracking-wide text-gray-400 uppercase">Person or party involved</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $this->opened->against ?? '—' }}</dd></div>
                <div><dt class="text-xs font-medium tracking-wide text-gray-400 uppercase">When it happened</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $this->opened->incident_on?->format('M j, Y') ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs font-medium tracking-wide text-gray-400 uppercase">Who saw it</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $this->opened->witnesses ?? '—' }}</dd></div>
            </dl>
            @if ($this->opened->resignation_id)
                {{-- Opened by a resignation for a just cause: the details are their resignation letter. --}}
                <x-ui.alert tone="amber" class="mt-4">Opened from their resignation for a just cause (Art. 300(b)): the text below is their letter. Find out what happened, then close it with the outcome.
                    @if (\App\Models\Resignation::handlesAny(auth()->user()))<a href="{{ route('resignations', ['open' => $this->opened->resignation_id]) }}" wire:navigate class="font-medium underline">See the resignation</a>@endif
                </x-ui.alert>
            @endif
            <div class="mt-4 rounded-md bg-gray-50 p-3 text-sm whitespace-pre-line text-gray-700 dark:bg-gray-800/60 dark:text-gray-200">{{ $this->opened->details }}</div>
            @include('livewire.partials.acknowledged', ['record' => $this->opened])

            {{-- Proof: optional for the employee. HR can ask for it; a case is investigated either way. --}}
            <div class="mt-4 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                <p class="text-xs font-medium tracking-wide text-gray-400 uppercase">Proof</p>
                @forelse ($this->opened->files as $file)
                    <a href="{{ route('complaint.file', $file) }}" target="_blank" class="mt-1 block truncate font-medium text-gray-700 underline decoration-gray-300 underline-offset-2 hover:text-blue-600 dark:text-gray-200 dark:hover:text-blue-400">{{ $file->name ?? 'Proof' }} <span class="font-normal text-gray-500">· {{ $file->created_at->format('M j, g:i A') }}</span></a>
                @empty
                    <p class="mt-1 text-gray-600 dark:text-gray-300">None attached.</p>
                @endforelse

                @if ($this->opened->awaitsProof())
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">You asked on {{ $this->opened->proof_requested_at?->format('M j') }}: "{{ rtrim((string) $this->opened->proof_request_note, '. ') }}". They haven't added any since.</p>
                @elseif (! $this->opened->status->isClosed())
                    @if ($askingProof)
                        <div class="mt-3 space-y-2">
                            <x-ui.textarea label="What would help" wire:model="proofNote" rows="2" maxlength="1000" placeholder="e.g. A screenshot of the messages, or the name of the guard who was there." />
                            <div class="flex gap-2">
                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="$set('askingProof', false)">Cancel</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="askForProof" wire:loading.attr="disabled" wire:target="askForProof">Send to {{ $this->opened->user->name }}</x-ui.button>
                            </div>
                        </div>
                    @else
                        <button type="button" wire:click="$set('askingProof', true)" class="mt-2 text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Ask for proof</button>
                    @endif
                @endif
            </div>

            @error('status') <x-ui.alert tone="red" class="mt-4">{{ $message }}</x-ui.alert> @enderror

            <form id="complaint-form" wire:submit="save" class="mt-4 space-y-4">
                <x-ui.select label="Status" wire:model.live="status">
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.textarea :label="\App\Enums\ComplaintStatus::tryFrom($status)?->isClosed() ? 'Outcome for the employee' : 'Outcome for the employee (when you close it)'" wire:model="resolution" rows="4" maxlength="5000" />
            </form>

            <x-slot:footer>
                <x-ui.button form="complaint-form" wire:loading.attr="disabled" wire:target="save">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
