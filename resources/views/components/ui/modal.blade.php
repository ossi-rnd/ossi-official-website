@props(['title', 'close', 'maxWidth' => 'md'])

@php
    $widths = ['sm' => 'max-w-sm', 'md' => 'max-w-md', 'lg' => 'max-w-lg', 'xl' => 'max-w-xl', '2xl' => 'max-w-2xl', '3xl' => 'max-w-3xl', '4xl' => 'max-w-4xl', '5xl' => 'max-w-5xl'];
@endphp

{{--
    Rendered by Livewire inside @if, so the server decides when it is open.
    `close` is any Livewire action that closes it: "cancelReview" or "$set('open', false)".
    Only the ✕ button (or the form's Cancel) closes it: a click on the backdrop
    or Esc does nothing, so a half-filled form can't vanish by accident.

    Never taller than the screen: the panel is capped at the viewport height,
    header and footer stay put, and only the body scrolls. (Centering a panel
    taller than the screen pushes its top out of reach.)
--}}
{{--
    Animation: wire:transition names the backdrop and the panel, and Livewire
    runs the browser's View Transitions API whenever it adds or removes them.
    That animates closes triggered by the SERVER too (e.g. after Save), which
    an Alpine-only transition can't: the element is already gone by then.
    Keyframes live in resources/css/app.css.
--}}
<div wire:transition="modal-backdrop" class="ui-modal-backdrop fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 p-4"
    role="dialog" aria-modal="true" aria-labelledby="modal-title"
    x-data
    x-init="$nextTick(() => $el.querySelector('input:not([type=hidden]), textarea')?.focus())">
    <div wire:transition="modal-panel" {{ $attributes->class('ui-modal-panel flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-gray-900 '.($widths[$maxWidth] ?? $widths['md'])) }}>
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-100 px-6 py-4 dark:border-gray-800">
            <h2 id="modal-title" class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $title }}</h2>
            <button type="button" wire:click="{{ $close }}" aria-label="Close" class="rounded-md p-1 text-gray-400 hover:bg-gray-50 hover:text-gray-600 dark:hover:bg-white/5">
                <x-icon name="x" class="h-5 w-5" />
            </button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-5">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="flex shrink-0 flex-wrap items-center justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-800">{{ $footer }}</div>
        @endisset
    </div>
</div>
