@props(['disabledReason' => null])

{{--
    Delete / Remove / Cancel at the end of a table row. Grey until hovered,
    so a column of them doesn't shout red down the page; the red is kept
    for the moment you aim at it. Pass wire:click and wire:confirm as usual.

    `disabled-reason`: when the delete would be refused anyway (guards still
    deployed, a training someone completed), say why instead of letting the
    click fail after the confirmation:
        <x-ui.row-delete wire:click="delete(1)" :disabled-reason="$count ? 'Move them first.' : null">Delete</x-ui.row-delete>
--}}
@if (filled($disabledReason))
    <x-ui.tooltip :text="$disabledReason" align="end" class="ml-4">
        <button type="button" disabled aria-disabled="true" class="cursor-not-allowed font-medium text-gray-300 dark:text-gray-600">{{ $slot }}</button>
    </x-ui.tooltip>
@else
    <button type="button" {{ $attributes->class('ml-4 font-medium text-gray-500 transition-colors hover:text-red-600 focus-visible:text-red-600 dark:text-gray-400 dark:hover:text-red-400') }}>{{ $slot }}</button>
@endif
