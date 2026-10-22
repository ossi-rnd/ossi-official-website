<div class="mx-auto max-w-3xl space-y-6">
    <x-ui.card title="Request leave" description="Your supervisor or HR reviews it. You can cancel while it's still pending.">
        <form wire:submit="submit" class="space-y-4 p-6">
            {{-- The company's leaves, then those under special laws (with the law). wire:key: the custom select re-reads its options. --}}
            <x-ui.select label="Leave type" wire:model.live="type">
                @foreach ($groups as $group)
                    @foreach ($group as $leaveType)
                        <option value="{{ $leaveType->value }}">{{ $leaveType->label() }}{{ $leaveType->law() ? ' ('.$leaveType->law().')' : '' }}</option>
                    @endforeach
                @endforeach
            </x-ui.select>

            @php $days = $this->daysAsked(); $needed = $chosen->document($days ?? 0); @endphp
            @if ($chosen->entitlement() || $chosen === \App\Enums\LeaveType::Sick)
                <div class="space-y-1 rounded-lg border border-gray-200 px-4 py-3 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">
                    @if ($chosen->entitlement())
                        <p><span class="font-medium text-gray-800 dark:text-gray-100">What the law gives ({{ $chosen->law() }}):</span> {{ $chosen->entitlement() }}.</p>
                        <p><span class="font-medium text-gray-800 dark:text-gray-100">Needed before it's approved:</span> {{ $chosen->document(1) }}. Attach it now or later from your history below.</p>
                        @if ($chosen->isConfidential())
                            <p class="text-xs text-gray-500 dark:text-gray-400">Confidential: it goes straight to {{ auth()->user()?->isOfficeStaff() ? 'the President or Vice President' : 'HR' }}, not through Operations, and only they see it.</p>
                        @endif
                        @if ($days !== null && $chosen->maxDays() !== null && $days > $chosen->maxDays())
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $days }} days is more than the law gives; whoever approves it will see that.</p>
                        @endif
                    @else
                        {{-- Key point first on each line, like the special leaves' "What the law gives:". --}}
                        <p><span class="font-medium text-gray-800 dark:text-gray-100">{{ \App\Support\LeaveRules::sickCertificateFromDays() }} days or more:</span> attach a medical certificate. It's needed before your leave is approved.</p>
                        <p><span class="font-medium text-gray-800 dark:text-gray-100">Already back at work?</span> You can still file, up to {{ \App\Support\LeaveRules::fileAfterDays() }} days after you got sick, and attach the certificate then.</p>
                    @endif
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input label="Start date" type="date" wire:model.live="start_date" :min="$this->earliestStart()->toDateString()" />
                <x-ui.input label="End date" type="date" wire:model.live="end_date" :min="$this->earliestStart()->toDateString()" />
            </div>

            <x-ui.textarea label="Reason" wire:model="reason" rows="3" maxlength="1000" />

            @if ($needed)
                <div>
                    <label for="leave-documents" class="ui-label">{{ $needed }} <span class="font-normal text-gray-500">(photo or PDF; optional now)</span></label>
                    <input id="leave-documents" type="file" multiple wire:model="documents" accept="image/jpeg,image/png,application/pdf"
                        class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border file:border-gray-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-50 dark:text-gray-300 dark:file:border-gray-700 dark:file:bg-gray-800 dark:file:text-gray-200 dark:hover:file:bg-gray-700">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Only you and whoever approves it can open it.</p>
                    @error('documents') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    @error('documents.*') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @endif

            <x-ui.button wire:loading.attr="disabled" wire:target="submit">
                Submit request
            </x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card title="My leave history">
        @error('leave') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Filed</th><th class="ui-th">Type</th><th class="ui-th">Dates</th><th class="ui-th">Reason</th><th class="ui-th">Status</th><th class="ui-th">Reviewer note</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->requests as $request)
                        <tr wire:key="leave-{{ $request->id }}">
                            <td class="ui-td"><x-filed-at :at="$request->created_at" /></td>
                            <td class="ui-td">{{ $request->type->label() }}</td>
                            <td class="ui-td whitespace-nowrap">{{ $request->dateRangeLabel() }}</td>
                            <td class="ui-td max-w-xs truncate" title="{{ $request->reason }}">{{ $request->reason }}</td>
                            <td class="ui-td">
                                <x-ui.status-badge :status="$request->status" />
                                {{-- Where it is on its way up: "With Operation HQ". --}}
                                @if ($request->waitingLabel()) <p class="mt-1 text-xs text-gray-500">{{ $request->waitingLabel() }}</p> @endif
                                {{-- The proof: what's attached, or what's still needed. --}}
                                @foreach ($request->documents as $document)
                                    <a href="{{ route('leave.document', $document) }}" target="_blank" class="mt-1 block truncate text-xs font-medium text-gray-600 underline decoration-gray-300 underline-offset-2 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400">{{ $document->name ?? 'Document' }}</a>
                                @endforeach
                                @if ($request->status === \App\Enums\RequestStatus::Pending && $request->documentNeeded())
                                    @if ($request->documents->isEmpty()) <p class="mt-1 text-xs text-gray-500">Needs: {{ lcfirst($request->documentNeeded()) }}</p> @endif
                                    <button type="button" wire:click="startAttaching({{ $request->id }})" class="mt-1 text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Attach</button>
                                @endif
                            </td>
                            <td class="ui-td max-w-xs truncate">{{ $request->review_note ?? '—' }}</td>
                            <td class="ui-td text-right">
                                @if ($request->status === \App\Enums\RequestStatus::Pending)
                                    <x-ui.row-delete wire:click="cancel({{ $request->id }})" wire:confirm="Cancel this leave request?">Cancel</x-ui.row-delete>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-td py-8 text-center">No leave requests yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($attachingId)
        <x-ui.modal title="Attach a document" close="stopAttaching" max-width="md">
            <form id="attach-form" wire:submit="attach" class="space-y-3">
                <label for="leave-attachment" class="ui-label">Photo or PDF</label>
                <input id="leave-attachment" type="file" wire:model="attachment" accept="image/jpeg,image/png,application/pdf"
                    class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border file:border-gray-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-50 dark:text-gray-300 dark:file:border-gray-700 dark:file:bg-gray-800 dark:file:text-gray-200 dark:hover:file:bg-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400">Only you and whoever approves it can open it. Whoever reviews it is told.</p>
                @error('attachment') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @error('document') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </form>
            <x-slot:footer>
                <x-ui.button form="attach-form" wire:target="attach,attachment">Attach</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
