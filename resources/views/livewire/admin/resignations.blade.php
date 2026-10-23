<div>
    <x-ui.card title="Resignations" description="Accept the last day, then tick the clearance. The day after the last day the account closes (status Resigned); final pay is due within 30 days of it.">
        <x-slot:actions>
            <x-ui.select wire:model.live="statusFilter" aria-label="Filter by status">
                <option value="open">In progress</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
                <option value="all">All</option>
            </x-ui.select>
            <x-ui.button type="button" variant="dark" size="sm" wire:click="startRecording"><x-icon name="document-plus" class="h-4 w-4" /> Record a resignation</x-ui.button>
        </x-slot:actions>

        <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-50" wire:target="statusFilter">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Employee</th><th class="ui-th">Filed</th><th class="ui-th">Reason</th><th class="ui-th">Last day</th><th class="ui-th">Status</th><th class="ui-th">Clearance</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->resignations as $resignation)
                        @php $notice = $resignation->noticeDays($resignation->effectiveLastDay()); @endphp
                        <tr wire:key="resignation-{{ $resignation->id }}">
                            <td class="ui-td">
                                <p><x-person-link :user="$resignation->user" /></p>
                                <p class="text-xs text-gray-500">{{ $resignation->user->employeeProfile?->assignmentLabel() ?? '—' }}</p>
                            </td>
                            <td class="ui-td"><x-filed-at :at="$resignation->created_at" /></td>
                            <td class="ui-td">{{ $resignation->reasonLabel() }}</td>
                            <td class="ui-td whitespace-nowrap">
                                {{ $resignation->effectiveLastDay()->format('M j, Y') }}
                                <span class="block text-xs text-gray-500">
                                    {{ $notice }} days' notice
                                    @if ($resignation->notice_waived) · waived @elseif ($resignation->asks_waiver && $resignation->status === \App\Enums\ResignationStatus::Pending) · asks to waive @endif
                                </span>
                            </td>
                            <td class="ui-td"><x-ui.badge :tone="$resignation->status->tone()">{{ $resignation->status->label() }}</x-ui.badge></td>
                            <td class="ui-td whitespace-nowrap text-gray-600 dark:text-gray-300">
                                @if ($resignation->status === \App\Enums\ResignationStatus::Pending) — @else {{ $resignation->clearedCount() }} of {{ count($resignation->clearanceItems()) }} @endif
                            </td>
                            <td class="ui-td text-right"><button type="button" wire:click="open({{ $resignation->id }})" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Open</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-td py-8 text-center">No resignations here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>{{ $this->resignations->links() }}</x-slot:footer>
    </x-ui.card>

    {{-- Why people leave: the reasons ticked, so HR can see patterns (pay, distance, the work). --}}
    @php $summary = $this->reasonSummary; $top = max(1, $summary['rows'][0]['total'] ?? 1); @endphp
    @if ($summary['filed'] > 0)
        <x-ui.card class="mt-6" title="Why people leave" :description="'From the '.$summary['filed'].' '.str('resignation')->plural($summary['filed']).' filed in the last 12 months (withdrawn ones not counted). Someone can tick more than one reason.'">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($summary['rows'] as $row)
                    <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1.5 px-6 py-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto]">
                        <span class="text-sm text-gray-800 dark:text-gray-100">
                            {{ $row['reason']->labelForOthers() }}
                            @if ($row['reason']->article())<span class="block text-xs text-gray-500 dark:text-gray-400">Just cause, {{ $row['reason']->article() }}</span>@endif
                        </span>
                        <span class="order-last col-span-2 h-1.5 overflow-hidden rounded-full bg-gray-100 sm:order-none sm:col-span-1 dark:bg-gray-800" aria-hidden="true">
                            <span class="block h-full rounded-full bg-blue-500" style="width: {{ round($row['total'] / $top * 100) }}%"></span>
                        </span>
                        <span class="text-right text-sm whitespace-nowrap tabular-nums">
                            <span class="font-semibold text-gray-800 dark:text-gray-100">{{ $row['total'] }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $row['guards'] }} {{ str('guard')->plural($row['guards']) }}, {{ $row['office'] }} office</span>
                        </span>
                    </li>
                @endforeach
            </ul>
            @if ($summary['others'] !== [])
                <x-slot:footer>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><span class="font-medium text-gray-700 dark:text-gray-300">"Others", in their words:</span> {{ collect($summary['others'])->map(fn ($text) => '“'.$text.'”')->implode(' · ') }}</p>
                </x-slot:footer>
            @endif
        </x-ui.card>
    @endif

    {{-- One resignation: the letter, then accept (pending) or the clearance (accepted). --}}
    @if ($opened = $this->opened)
        @php $pending = $opened->status === \App\Enums\ResignationStatus::Pending; @endphp
        <x-ui.modal :title="$opened->user->name.' · resignation'" close="close" max-width="2xl">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Filed</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $opened->created_at->format('M j, Y') }}@if ($opened->recorder) <span class="text-gray-500">· by {{ $opened->recorder->name }}</span>@endif</dd></div>
                <div><dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Reason</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $opened->reasonLabel() }}@if ($opened->skipsNotice())<span class="block text-xs text-gray-500 dark:text-gray-400">Just cause ({{ collect($opened->reasonList())->map->article()->filter()->implode(', ') }}): no notice needed. Read the letter.</span>@endif</dd></div>
                <div><dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Last day asked</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $opened->requested_last_day->format('M j, Y') }} <span class="text-gray-500">· {{ $opened->noticeDays() }} days' notice</span></dd></div>
            </dl>
            <div class="mt-4 rounded-md bg-gray-50 p-3 text-sm whitespace-pre-line text-gray-700 dark:bg-gray-800/60 dark:text-gray-300">{{ $opened->letter }}</div>
            {{-- A just cause opened a complaint case: what happened is looked into there. --}}
            @if ($opened->complaint)
                <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">
                    Complaint <span class="font-medium">{{ $opened->complaint->reference() }}</span> was opened about what happened · {{ $opened->complaint->status->label() }}
                    @if (\App\Models\Complaint::handlesAny(auth()->user()))· <a href="{{ route('complaint-cases', ['open' => $opened->complaint->id]) }}" wire:navigate class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Open the case</a>@endif
                </p>
            @endif
            {{-- What they confirmed before sending, word for word (none for a paper letter). --}}
            @include('livewire.partials.acknowledged', ['record' => $opened])

            @if ($pending)
                {{-- Accept: there's no "reject" (resigning is the employee's right); only the date to settle. --}}
                <form id="accept-form" wire:submit="accept" class="mt-5 space-y-4">
                    @if ($opened->asks_waiver)
                        <x-ui.alert tone="amber">They ask to leave with less than 30 days' notice. Accepting a last day before {{ $opened->created_at->addDays(\App\Models\Resignation::NOTICE_DAYS)->format('M j') }} waives the rest.</x-ui.alert>
                    @endif
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.input label="Last day" type="date" wire:model="lastDay" hint="As asked, or another date agreed with them." />
                        <x-ui.input label="Note to the employee (optional)" wire:model="note" maxlength="1000" />
                    </div>
                </form>
            @else
                <div class="mt-5 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                    <div class="rounded-md border border-gray-200 p-3 dark:border-gray-800"><p class="text-xs text-gray-500 dark:text-gray-400">Last day</p><p class="mt-0.5 font-semibold text-gray-800 dark:text-gray-100">{{ $opened->effectiveLastDay()->format('M j, Y') }}</p>@if ($opened->notice_waived)<p class="text-xs text-amber-700 dark:text-amber-400">notice waived</p>@endif</div>
                    <div class="rounded-md border border-gray-200 p-3 dark:border-gray-800"><p class="text-xs text-gray-500 dark:text-gray-400">Account</p><p class="mt-0.5 font-semibold text-gray-800 dark:text-gray-100">{{ $opened->separated_at ? 'Closed '.$opened->separated_at->format('M j') : 'Closes '.$opened->effectiveLastDay()->addDay()->format('M j') }}</p></div>
                    <div class="rounded-md border border-gray-200 p-3 dark:border-gray-800"><p class="text-xs text-gray-500 dark:text-gray-400">Final pay due</p><p class="mt-0.5 font-semibold text-gray-800 dark:text-gray-100">{{ $opened->finalPayDue()->format('M j, Y') }}</p></div>
                </div>

                <div class="mt-5">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Clearance <span class="font-normal text-gray-500">· {{ $opened->clearedCount() }} of {{ count($opened->clearanceItems()) }}</span></p>
                    @error('clearance') <x-ui.alert tone="red" class="mt-2">{{ $message }}</x-ui.alert> @enderror
                    <ul class="mt-2 divide-y divide-gray-100 rounded-md border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                        @foreach ($opened->clearanceItems() as $item)
                            @php $done = ($opened->clearance ?? [])[$item->value] ?? null; @endphp
                            <li wire:key="clearance-{{ $item->value }}">
                                <label class="flex cursor-pointer items-start gap-3 px-3 py-2.5 hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                                    <input type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600"
                                        @checked($done) @disabled($opened->status === \App\Enums\ResignationStatus::Completed) wire:click="toggle('{{ $item->value }}')" wire:loading.attr="disabled" wire:target="toggle">
                                    <span class="min-w-0 flex-1 text-sm">
                                        <span @class(['text-gray-800 dark:text-gray-100', 'line-through decoration-gray-300 dark:decoration-gray-600' => $done])>{{ $item->label() }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                            @if ($done) {{ $done['by'] }} · {{ \Carbon\CarbonImmutable::parse($done['at'])->format('M j, g:i A') }}
                                            @elseif ($item === \App\Enums\ClearanceItem::FirearmAndEquipment && $this->radiosHeld !== []) Company property still with them: {{ implode(', ', $this->radiosHeld) }}. Mark it returned on Equipment (or Radios).
                                            @elseif ($item === \App\Enums\ClearanceItem::LoansSettled) {{ $this->owed > 0 ? '₱'.number_format($this->owed, 2).' still owed on loans and load' : 'Nothing owed' }}
                                            @elseif ($item === \App\Enums\ClearanceItem::FinalPay) due by {{ $opened->finalPayDue()->format('M j, Y') }}
                                            @elseif ($item === \App\Enums\ClearanceItem::Certificate) within 3 days of their request (DOLE Labor Advisory 06-20)
                                            @endif
                                        </span>
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    @if ($opened->status === \App\Enums\ResignationStatus::Completed)
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Completed {{ $opened->completed_at?->format('M j, Y') }}.</p>
                    @elseif ($opened->fullyCleared() && ! $opened->separated_at)
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">All cleared. It completes when the account closes, the day after the last day.</p>
                    @endif
                </div>
            @endif

            @if ($pending)
                <x-slot:footer>
                    <x-ui.button form="accept-form" wire:target="accept">Accept resignation</x-ui.button>
                </x-slot:footer>
            @endif
        </x-ui.modal>
    @endif

    {{-- A paper letter, entered for the employee. --}}
    @if ($recording)
        <x-ui.modal title="Record a resignation" close="cancelRecording" max-width="lg">
            <form id="record-form" wire:submit="record" class="space-y-4">
                <x-ui.select label="Employee" wire:model="employeeId">
                    <option value="">Choose…</option>
                    @foreach ($this->employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                    @endforeach
                </x-ui.select>
                @include('livewire.partials.resignation-reasons', ['idPrefix' => 'record', 'self' => false])
                <div class="sm:w-1/2">
                    <x-ui.input label="Last day asked" type="date" wire:model="requestedLastDay" />
                </div>
                <x-ui.textarea label="The letter (as written)" wire:model="letter" rows="5" maxlength="5000" />
                <x-ui.checkbox id="record-waiver" wire:model="asksWaiver" label="They ask to waive part of the 30-day notice" />
                <x-ui.checkbox id="record-consent" wire:model="consentsToDeduction" label="They signed consent to deduct what they owe from final pay and cash bond" description="Only tick this with their signed form (Labor Code Art. 113)." />
                @error('lastDay') <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror
            </form>
            <x-slot:footer>
                <x-ui.button form="record-form" wire:target="record">Record</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
