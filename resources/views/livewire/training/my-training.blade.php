<div class="mx-auto max-w-3xl space-y-6">
    <x-ui.card title="Ask for a training" description="A refresher, training for your license renewal, first aid. Admin decides and adds you to a session.">
        <form wire:submit="submit" class="space-y-4 p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.select label="Training" wire:model="kind">
                    <option value="">Choose one</option>
                    @foreach (\App\Enums\TrainingRequestKind::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Preferred start (optional)" type="date" wire:model="preferredFrom" min="{{ today()->toDateString() }}" />
            </div>
            <x-ui.textarea label="Why" wire:model="reason" rows="3" maxlength="1000" placeholder="e.g. My security guard license expires in March and I need the refresher to renew it." />
            <x-ui.button wire:loading.attr="disabled" wire:target="submit">Send request</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card title="My requests">
        @error('list') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror
        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Filed</th><th class="ui-th">Training</th><th class="ui-th">Status</th><th class="ui-th">Note</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->requests as $request)
                        <tr wire:key="tr-{{ $request->id }}">
                            <td class="ui-td"><x-filed-at :at="$request->created_at" /></td>
                            <td class="ui-td">
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ $request->kind->label() }}</p>
                                @if ($request->preferred_from) <p class="text-xs text-gray-500">From {{ $request->preferred_from->format('M j, Y') }}</p> @endif
                            </td>
                            <td class="ui-td">{{ $request->outcomeLabel() }}</td>
                            <td class="ui-td max-w-xs truncate">{{ $request->review_note ?? '—' }}</td>
                            <td class="ui-td text-right">
                                @if ($request->status === \App\Enums\RequestStatus::Pending)
                                    <button type="button" wire:click="cancel({{ $request->id }})" wire:confirm="Cancel this request?" class="font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100">Cancel</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-td py-8 text-center">No requests yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.card title="My trainings" description="Sessions you're in or attended. Completed ones are on your record.">
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->sessions as $attendee)
                <li wire:key="session-{{ $attendee->id }}" class="flex items-center justify-between gap-3 px-6 py-3 text-sm">
                    <span class="min-w-0">
                        <span class="block font-medium text-gray-800 dark:text-gray-100">{{ $attendee->training->title }}</span>
                        <span class="block text-xs text-gray-500">{{ $attendee->training->dateLabel() }}{{ $attendee->training->venue ? ' · '.$attendee->training->venue : '' }}{{ $attendee->status === $completed && $attendee->training->valid_until ? ' · valid until '.$attendee->training->valid_until->format('M j, Y') : '' }}</span>
                    </span>
                    <x-ui.badge :tone="$attendee->status->tone()">{{ $attendee->status->label() }}</x-ui.badge>
                </li>
            @empty
                <li class="px-6 py-8 text-center text-sm text-gray-500">None yet.</li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
