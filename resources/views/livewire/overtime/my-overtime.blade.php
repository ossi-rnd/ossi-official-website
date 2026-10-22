<div class="mx-auto max-w-3xl space-y-6">
    <x-ui.card title="Request overtime" :description="'Goes to '.$this->sentTo().' automatically. Only APPROVED overtime is paid, and only up to the approved hours.'">
        <form wire:submit="submit" class="space-y-4 p-6">
            <div>
                <p class="ui-label">Post / Detachment</p>
                <p class="mt-1 text-sm text-gray-800 dark:text-gray-100">{{ $this->post ?? 'Not set on your profile' }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input label="Date" type="date" wire:model="date" />
                <x-ui.input label="Expected extra hours" type="number" step="0.25" min="0.25" max="12" wire:model="hours" hint="e.g. 1.5 for 1 hour 30 minutes" />
            </div>

            <x-ui.textarea label="Reason" wire:model="reason" rows="3" maxlength="1000" />

            <x-ui.button wire:loading.attr="disabled" wire:target="submit">
                Submit request
            </x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card title="My overtime requests">
        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Filed</th><th class="ui-th">Date</th><th class="ui-th">Requested</th><th class="ui-th">Approved</th><th class="ui-th">Sent to</th><th class="ui-th">Status</th><th class="ui-th">Note</th></tr></thead>
                <tbody>
                    @forelse ($this->requests as $request)
                        <tr wire:key="ot-{{ $request->id }}">
                            <td class="ui-td"><x-filed-at :at="$request->created_at" /></td>
                            <td class="ui-td whitespace-nowrap">{{ $request->date->format('M j, Y') }}</td>
                            <td class="ui-td">{{ intdiv($request->requested_minutes, 60) }}h {{ $request->requested_minutes % 60 }}m</td>
                            <td class="ui-td">{{ $request->approved_minutes !== null ? intdiv($request->approved_minutes, 60).'h '.($request->approved_minutes % 60).'m' : '—' }}</td>
                            <td class="ui-td">{{ $request->sentToLabel() }}</td>
                            <td class="ui-td"><x-ui.status-badge :status="$request->status" /></td>
                            <td class="ui-td max-w-xs truncate">{{ $request->review_note ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-td py-8 text-center">No overtime requests yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
