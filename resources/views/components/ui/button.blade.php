@props(['variant' => 'primary', 'size' => 'md', 'type' => 'submit', 'href' => null, 'disabledReason' => null, 'tooltipAlign' => 'center'])

{{--
    `disabled-reason`: pass WHY the button is locked and it is disabled with
    that explanation as a tooltip. Prefer it over a bare :disabled, so a
    locked button never leaves people guessing.
        <x-ui.button :disabled-reason="$blocked ? 'Generate all payslips first.' : null">Approve</x-ui.button>

    Loading: every button shows a spinner while it's busy, with nothing to add:
      - a Livewire action it started (wire:click, or the submit button of a wire:submit
        form, even one outside the form via form="…"): Livewire sets data-loading on it;
      - a plain form it submitted (login, register…): resources/js/form-loading.js does;
      - with wire:target, also while THAT runs (e.g. an upload still in flight).
    The spinner covers the label, so the button never changes size.
--}}
@php
    // Full class names only: Tailwind can't see names built at runtime.
    //
    // Colour rules (the theme is blue + grays):
    //   primary        the ONE main action of a screen or modal (Approve, Save, Submit).
    //                  A small inline form INSIDE a modal (Add adjustment, Add deduction)
    //                  submits with secondary: the modal's footer action stays the only blue.
    //   dark           toolbar "create" actions and row actions in tables
    //   secondary      everything else
    //   danger-outline a destructive action that loses something (Delete draft, Turn off 2FA). Reject is NOT
    //                  one: it's a normal decision, so it's secondary beside the blue Approve.
    //   danger         only the final "yes, do the destructive thing" inside a confirmation modal
    // There is deliberately no green: two loud colours side by side compete, and green isn't in the palette.
    $variants = [
        'primary' => 'bg-blue-600 text-white shadow-sm hover:bg-blue-500 focus-visible:ring-blue-500',
        'dark' => 'bg-gray-900 text-white shadow-sm hover:bg-gray-800 focus-visible:ring-gray-500 dark:bg-white dark:text-gray-800 dark:hover:bg-gray-200',
        'secondary' => 'border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 focus-visible:ring-blue-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700',
        'danger-outline' => 'border border-red-200 bg-white text-red-600 shadow-sm hover:border-red-300 hover:bg-red-50 focus-visible:ring-red-500 dark:border-red-500/30 dark:bg-gray-800 dark:text-red-400 dark:hover:bg-red-500/10',
        'danger' => 'bg-red-600 text-white shadow-sm hover:bg-red-500 focus-visible:ring-red-500',
        'ghost' => 'text-gray-600 hover:bg-gray-100 hover:text-gray-800 focus-visible:ring-gray-400 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white',
    ];
    $sizes = [
        // Same 40px height as inputs and selects; "sm" only tightens the sides.
        'sm' => 'min-h-10 px-3 text-sm',
        'md' => 'min-h-10 px-4 text-sm',
        'lg' => 'min-h-12 px-5 text-base',
    ];
    $classes = 'inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 '
        .($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
    // Busy = data-loading (set by Livewire or form-loading.js) or .is-busy (wire:target below).
    // No double clicks while busy; "progress" cursor says it's working.
    $busyClasses = 'group relative data-loading:pointer-events-none data-loading:cursor-progress [&.is-busy]:pointer-events-none [&.is-busy]:cursor-progress';
    $locked = filled($disabledReason);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@elseif ($locked)
    {{-- Clicks never reach the action: the button is disabled and wire:click is dropped. --}}
    <x-ui.tooltip :text="$disabledReason" :align="$tooltipAlign">
        <button type="button" disabled aria-disabled="true" {{ $attributes->whereDoesntStartWith(['wire:click', 'wire:confirm', 'x-on:click', '@click'])->class($classes) }}>{{ $slot }}</button>
    </x-ui.tooltip>
@else
    <button type="{{ $type }}"
        @if ($attributes->has('wire:target')) wire:loading.class="is-busy" @endif
        {{ $attributes->class([$classes, $busyClasses]) }}>
        <span class="inline-flex items-center justify-center gap-1.5 group-data-loading:invisible group-[.is-busy]:invisible">{{ $slot }}</span>
        <span class="absolute inset-0 hidden items-center justify-center group-data-loading:flex group-[.is-busy]:flex">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" />
                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
            <span class="sr-only">Working…</span>
        </span>
    </button>
@endif
