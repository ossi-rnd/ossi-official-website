<div class="mx-auto max-w-3xl space-y-6">
    @if ($deployed)
        <x-ui.card title="Can't report for duty?" description="Tell Operations as early as you can so they can send a reliever to your post. This isn't leave: for a planned day off, use My Leave.">
            <form wire:submit="submit" class="space-y-4 p-6">
                <p class="text-sm text-gray-600 dark:text-gray-300">Your post: <span class="font-medium text-gray-800 dark:text-gray-100">{{ $place }}</span></p>
                <x-ui.input label="Duty date" type="date" wire:model="dutyDate" :min="today()->toDateString()" :max="$maxDate" />
                <x-ui.textarea label="Reason" wire:model="reason" rows="3" maxlength="1000" placeholder="e.g. Fever since last night, going to the clinic." />

                <x-ui.button wire:loading.attr="disabled" wire:target="submit">
                    Notify Operations
                </x-ui.button>
            </form>
        </x-ui.card>
    @else
        <x-ui.alert tone="blue">Absence notices are for guards deployed to a post. Office staff file a request on My Leave.</x-ui.alert>
    @endif

    <x-ui.card title="My notices">
        @error('notice') <x-ui.alert tone="red" class="m-4">{{ $message }}</x-ui.alert> @enderror

        <div class="overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th class="ui-th">Duty date</th><th class="ui-th">Post</th><th class="ui-th">Reason</th><th class="ui-th">Status</th><th class="ui-th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($this->notices as $notice)
                        <tr wire:key="notice-{{ $notice->id }}">
                            <td class="ui-td whitespace-nowrap">{{ $notice->duty_date->format('D, M j, Y') }}</td>
                            <td class="ui-td">{{ $notice->placeLabel() }}</td>
                            <td class="ui-td max-w-xs truncate" title="{{ $notice->reason }}">{{ $notice->reason }}</td>
                            <td class="ui-td">
                                @if ($notice->isNoShow()) <x-ui.badge tone="red">No-show</x-ui.badge> @endif
                                <x-ui.badge :tone="$notice->status->tone()">{{ $notice->status->label() }}</x-ui.badge>
                                @if ($notice->reliever)
                                    <p class="mt-1 text-xs text-gray-500">{{ $notice->reliever->name }}</p>
                                @endif
                            </td>
                            <td class="ui-td text-right">
                                @if ($notice->status === \App\Enums\AbsenceNoticeStatus::Open && ! $notice->isNoShow())
                                    <x-ui.row-delete wire:click="cancel({{ $notice->id }})" wire:confirm="You can report after all? This cancels the notice.">Cancel</x-ui.row-delete>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-td py-8 text-center">No notices yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
