<div class="space-y-6">
    @if (! $this->rejecting && ! $this->approving)
        @error('reason')<x-ui.alert tone="red">{{ $message }}</x-ui.alert>@enderror
    @endif

    <x-ui.card title="Borrow Load requests" description="Send the load from the company phone, then approve with a screenshot of the confirmation: it's the employee's receipt, and the amount is deducted from their next payslip.">
        <x-slot:actions>
            <x-ui.select wire:model.live="status" aria-label="Filter by status" class="mt-0! sm:w-48">
                @foreach ($filters as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Filed</th><th class="ui-th">Employee</th><th class="ui-th">Load</th><th class="ui-th">Send to</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->requests as $request)
                        <tr wire:key="load-{{ $request->id }}">
                            <td class="ui-td"><x-filed-at :at="$request->created_at" /></td>
                            <td class="ui-td"><x-person-link :user="$request->user" /></td>
                            <td class="ui-td font-semibold whitespace-nowrap text-gray-800 dark:text-gray-100">₱{{ number_format($request->amount) }} {{ $request->networkLabel() }}</td>
                            <td class="ui-td font-mono text-xs whitespace-nowrap">{{ $request->formattedNumber() }}</td>
                            <td class="ui-td">
                                <x-ui.badge :tone="$request->status->tone()">{{ $request->status->label() }}</x-ui.badge>
                                @if ($request->status === \App\Enums\LoadRequestStatus::Approved && $request->employee_deduction_id)
                                    <p class="mt-1 text-xs text-gray-500">On payroll as a Borrowed Load deduction
                                        @if ($request->hasProof()) · <a href="{{ route('load.receipt', $request) }}" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Receipt</a> @endif
                                    </p>
                                @endif
                                @if ($request->rejection_reason)<p class="mt-1 max-w-xs text-xs text-red-600 dark:text-red-400">{{ $request->rejection_reason }}</p>@endif
                            </td>
                            <td class="ui-td text-right whitespace-nowrap">
                                @if ($request->status->canMoveTo(\App\Enums\LoadRequestStatus::Approved))
                                    @if ($request->user_id === auth()->id())
                                        <x-ui.button type="button" size="sm" variant="secondary" tooltip-align="end" disabled-reason="Your own request: another Admin has to approve it.">Approve</x-ui.button>
                                    @else
                                        <button type="button" wire:click="startReject({{ $request->id }})" class="mr-3 text-sm font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white">Reject</button>
                                        <x-ui.button type="button" size="sm" variant="dark" wire:click="startApprove({{ $request->id }})">Approve</x-ui.button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="ui-td py-8 text-center">{{ $status === 'open' ? 'Nothing to do. Every request is handled.' : 'No load requests here.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>{{ $this->requests->links() }}</x-slot:footer>
    </x-ui.card>

    @if ($approving = $this->approving)
        <x-ui.modal title="Approve borrowed load" close="cancelApprove" max-width="md">
            <form id="approve-load" wire:submit="approve" class="space-y-4">
                <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-800/60">
                    <p class="font-semibold text-gray-800 dark:text-gray-100">₱{{ number_format($approving->amount) }} {{ $approving->networkLabel() }} to <span class="font-mono">{{ $approving->formattedNumber() }}</span></p>
                    <p class="mt-0.5 text-gray-600 dark:text-gray-300">For {{ $approving->user->name }}. ₱{{ number_format($approving->amount) }} will be deducted from their next payslip.</p>
                </div>
                <x-ui.file-input label="Proof the load was sent" model="proof" id="load-proof" accept="image/png,image/jpeg"
                    hint="A screenshot of the carrier's confirmation text or the e-load app. The employee gets it as their receipt, by email and in the app." />
            </form>
            <x-slot:footer>
                <x-ui.button form="approve-load" wire:loading.attr="disabled" wire:target="proof, approve">Approve and send receipt</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($this->rejecting)
        <x-ui.modal title="Reject load request" close="cancelReject" max-width="md">
            <form id="reject-load" wire:submit="reject" class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    ₱{{ number_format($this->rejecting->amount) }} {{ $this->rejecting->networkLabel() }} to {{ $this->rejecting->formattedNumber() }} for {{ $this->rejecting->user->name }}. Nothing is deducted.
                </p>
                <x-ui.textarea label="Reason (sent to the employee)" wire:model="reason" rows="3" maxlength="500" placeholder="e.g. Number is not reachable; please check it and request again." />
            </form>
            <x-slot:footer>
                <x-ui.button form="reject-load" wire:loading.attr="disabled" wire:target="reject">Reject request</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
