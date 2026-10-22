@php
    $chosen = \App\Enums\PayrollConcernCategory::tryFrom($category) ?? \App\Enums\PayrollConcernCategory::Other;
@endphp

{{-- Under the employee's payslip: their reports on it with Accounting's answers, and "Report a problem". --}}
<div class="space-y-3">
    @foreach ($this->concerns as $concern)
        <div wire:key="concern-{{ $concern->id }}" class="ui-card p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $concern->category->label() }}</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Reported {{ $concern->created_at->format('M j, g:i A') }}</p>
                </div>
                <x-ui.badge :tone="$concern->status->tone()">{{ $concern->status->label() }}</x-ui.badge>
            </div>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $concern->details }}</p>
            @if ($concern->attachment_path)
                <a href="{{ route('payroll-concerns.attachment', $concern) }}" target="_blank" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-gray-600 underline decoration-gray-300 underline-offset-2 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400">
                    <x-icon name="arrow-top-right-on-square" class="h-3.5 w-3.5" /> {{ $concern->attachment_name }}
                </a>
            @endif
            @if ($concern->response)
                <div class="mt-3 rounded-md bg-gray-50 p-3 text-sm dark:bg-white/[0.03]">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Accounting · {{ $concern->resolved_at?->format('M j') }}</p>
                    <p class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $concern->response }}</p>
                    @if ($concern->adjustment)
                        @php $amount = (float) $concern->adjustment->amount; @endphp
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $amount >= 0 ? 'Added' : 'Deducted' }} ₱{{ number_format(abs($amount), 2) }} on your {{ $concern->adjustment->run->periodLabel() }} payslip.</p>
                    @endif
                </div>
            @else
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Waiting for Accounting's answer.</p>
            @endif
        </div>
    @endforeach

    @if (! $reporting)
        <p class="text-center text-sm text-gray-500 dark:text-gray-400">
            Something wrong on this payslip?
            <button type="button" wire:click="$set('reporting', true)" class="font-medium text-gray-700 underline decoration-gray-300 underline-offset-4 hover:text-blue-600 dark:text-gray-300 dark:decoration-gray-600 dark:hover:text-blue-400">Report a problem</button>
        </p>
    @else
        <x-ui.card title="Report a problem" description="Accounting is told right away. If they find it's owed, it's added to your next payslip.">
            <form wire:submit="report" class="space-y-4 p-5">
                <x-ui.select label="What's wrong?" wire:model.live="category">
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.textarea label="Details, with the dates" wire:model="details" rows="3" maxlength="2000" :placeholder="$chosen->example()" />
                <div>
                    <label for="concern-attachment" class="ui-label">Photo or PDF <span class="font-normal text-gray-500">(optional: a DTR, a supervisor's note)</span></label>
                    <input id="concern-attachment" type="file" wire:model="attachment" accept="image/jpeg,image/png,application/pdf"
                        class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border file:border-gray-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-50 dark:text-gray-300 dark:hover:file:bg-gray-700 dark:file:border-gray-700 dark:file:bg-gray-800 dark:file:text-gray-200">
                    <p wire:loading wire:target="attachment" class="mt-1 text-xs text-gray-500">Uploading…</p>
                    @error('attachment') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" wire:click="$set('reporting', false)">Cancel</x-ui.button>
                    <x-ui.button wire:target="report,attachment">Send to Accounting</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
