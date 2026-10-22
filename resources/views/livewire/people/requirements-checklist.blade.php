@php
    $summary = $this->summary;
    $percent = $summary['total'] > 0 ? (int) round($summary['on_file'] / $summary['total'] * 100) : 0;
@endphp

<div>
    <x-ui.card title="201 file"
        :description="$summary['on_file'].' of '.$summary['total'].' documents on file'.($this->profile->is_office_staff ? ' (office staff: guard-only documents are not required)' : '')">
        <x-slot:actions>
            <x-ui.checkbox id="requirements-only-open" label="Only what needs action" wire:model.live="onlyOpen" />
        </x-slot:actions>

        <div class="space-y-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="201 file completion">
                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $percent }}%"></div>
            </div>
            @if ($summary['submitted'] > 0 || $summary['expired'] > 0)
                <div class="flex flex-wrap gap-2 text-xs">
                    @if ($summary['submitted'] > 0)<x-ui.badge tone="amber">{{ $summary['submitted'] }} awaiting HR check</x-ui.badge>@endif
                    @if ($summary['expired'] > 0)<x-ui.badge tone="red">{{ $summary['expired'] }} expired</x-ui.badge>@endif
                </div>
            @endif
        </div>

        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->visibleItems as $item)
                @php $record = $item->record; @endphp
                <li wire:key="requirement-{{ $item->requirement->value }}" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $item->requirement->label() }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            @if ($item->isExpired)
                                <x-ui.badge tone="red">Expired {{ $record?->expires_on?->format('M j, Y') }}</x-ui.badge>
                            @else
                                <x-ui.badge :tone="$item->status->tone()">{{ $item->status->label() }}</x-ui.badge>
                            @endif

                            @if (! $item->isExpired && $record?->expires_on)
                                <span @class(['font-medium text-amber-600 dark:text-amber-400' => $item->expiresSoon()])>
                                    Expires {{ $record->expires_on->format('M j, Y') }}@if ($item->expiresSoon()) ({{ $item->daysToExpiry === 0 ? 'today' : 'in '.$item->daysToExpiry.' '.str('day')->plural((int) $item->daysToExpiry) }})@endif
                                </span>
                            @endif

                            @if ($record?->promised_on)
                                <span @class(['font-medium text-red-600 dark:text-red-400' => $item->isPromiseOverdue])>
                                    {{ $item->isPromiseOverdue ? 'Overdue: promised by' : 'Will submit by' }} {{ $record->promised_on->format('M j, Y') }}
                                </span>
                            @endif

                            @if ($record?->hasFile())
                                <a href="{{ route('requirements.document', $record) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                                    <x-icon name="eye" class="h-3.5 w-3.5" /> {{ $record->file_name }}
                                </a>
                            @elseif ($item->status === \App\Enums\RequirementStatus::OnFile)
                                <span>Paper copy with HR</span>
                            @endif

                            @if ($record?->verifier && $item->status === \App\Enums\RequirementStatus::OnFile)
                                <span>Verified by {{ $record->verifier->name }}</span>
                            @endif
                        </div>
                        @if ($record?->note && $item->status === \App\Enums\RequirementStatus::Missing)
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">Returned by HR: {{ $record->note }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if ($item->status === \App\Enums\RequirementStatus::Missing && ($isOwner || $this->canReview))
                            <x-ui.button type="button" size="sm" variant="ghost" wire:click="startPromise('{{ $item->requirement->value }}')"><x-icon name="calendar" class="h-4 w-4" /> Set date</x-ui.button>
                        @endif
                        <x-ui.button type="button" size="sm" variant="secondary" wire:click="startUpload('{{ $item->requirement->value }}')">
                            <x-icon name="upload" class="h-4 w-4" /> {{ $record?->hasFile() ? 'Replace' : 'Upload' }}
                        </x-ui.button>
                        @if ($this->canReview)
                            <x-ui.button type="button" size="sm" :variant="$item->status === \App\Enums\RequirementStatus::Submitted ? 'dark' : 'secondary'" wire:click="startReview('{{ $item->requirement->value }}')">
                                <x-icon name="check" class="h-4 w-4" /> Review
                            </x-ui.button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-gray-500">Everything is on file.</li>
            @endforelse
        </ul>
    </x-ui.card>

    @if ($mode !== '' && $this->activeItem)
        @php $activeItem = $this->activeItem; $requirement = $activeItem->requirement; @endphp

        @if ($mode === 'upload')
            <x-ui.modal :title="'Upload: '.$requirement->label()" close="cancel" max-width="md">
                <form id="requirement-upload" wire:submit="submitDocument" class="space-y-4">
                    <x-ui.file-input label="Scan or photo" model="document" id="requirement-document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                        :hint="'PDF, JPG or PNG, up to '.((int) config('hr.documents.max_kilobytes') / 1024).' MB.'" />
                    @if ($requirement->expires())
                        <x-ui.input label="Valid until" type="date" wire:model="expiresOn" hint="As printed on the document. You'll be reminded before it expires." />
                    @endif
                    @unless ($this->canReview)
                        <x-ui.alert tone="blue" class="text-xs">HR checks every upload before it counts as on file.</x-ui.alert>
                    @endunless
                </form>
                <x-slot:footer>
                    <x-ui.button form="requirement-upload" wire:loading.attr="disabled" wire:target="document, submitDocument">Upload</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @elseif ($mode === 'review')
            <x-ui.modal :title="'Review: '.$requirement->label()" close="cancel" max-width="md">
                <form id="requirement-review" wire:submit="review" class="space-y-4">
                    @if ($activeItem->record?->hasFile())
                        <a href="{{ route('requirements.document', $activeItem->record) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                            <x-icon name="eye" class="h-4 w-4" /> Open {{ $activeItem->record->file_name }}
                        </a>
                    @else
                        <p class="text-sm text-gray-500">No upload. Choose "Mark on file" if HR holds the paper copy.</p>
                    @endif

                    <fieldset>
                        <legend class="ui-label">Decision</legend>
                        <div class="mt-2 space-y-2">
                            <label class="flex items-center gap-2 text-sm"><input type="radio" wire:model.live="decision" value="{{ \App\Actions\People\ReviewRequirement::FILE }}" class="text-blue-600 dark:text-blue-400"> Mark on file (verified)</label>
                            @if ($activeItem->status !== \App\Enums\RequirementStatus::Missing)
                                <label class="flex items-center gap-2 text-sm"><input type="radio" wire:model.live="decision" value="{{ \App\Actions\People\ReviewRequirement::RETURN }}" class="text-blue-600 dark:text-blue-400"> Return to the employee</label>
                            @endif
                        </div>
                    </fieldset>

                    @if ($decision === \App\Actions\People\ReviewRequirement::FILE && $requirement->expires())
                        <x-ui.input label="Valid until" type="date" wire:model="expiresOn" hint="Reminders go out 60, 30 and 7 days before, and on the day." />
                    @endif

                    <x-ui.textarea :label="$decision === \App\Actions\People\ReviewRequirement::RETURN ? 'Reason (sent to the employee)' : 'Note (optional)'" wire:model="note" rows="2" maxlength="500" />
                </form>
                <x-slot:footer>
                    <x-ui.button form="requirement-review" :variant="$decision === \App\Actions\People\ReviewRequirement::RETURN ? 'danger' : 'primary'" wire:loading.attr="disabled" wire:target="review">
                        {{ $decision === \App\Actions\People\ReviewRequirement::RETURN ? 'Return' : 'Mark on file' }}
                    </x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @elseif ($mode === 'promise')
            <x-ui.modal :title="'When will you submit it?'" close="cancel" max-width="sm">
                <form id="requirement-promise" wire:submit="promise" class="space-y-4">
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $requirement->label() }}</p>
                    <x-ui.input label="Submit by" type="date" wire:model="promisedOn" min="{{ today()->toDateString() }}" hint="HR and you get a reminder if it passes." />
                </form>
                <x-slot:footer>
                    <x-ui.button form="requirement-promise" wire:loading.attr="disabled" wire:target="promise">Save date</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endif
</div>
