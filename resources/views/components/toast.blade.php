{{-- Shows any `notify` event: $this->dispatch('notify', message: '...') from a Livewire component. --}}
<div x-data="{ message: '', visible: false, timer: null }"
    x-on:notify.window="message = $event.detail.message; visible = true; clearTimeout(timer); timer = setTimeout(() => visible = false, 4000)"
    x-show="visible" x-cloak x-transition.opacity role="status" aria-live="polite"
    class="fixed right-4 bottom-4 z-[60] flex max-w-sm items-start gap-2 rounded-lg bg-gray-900 px-4 py-3 text-sm text-white shadow-lg dark:bg-white dark:text-gray-800">
    <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-green-400 dark:text-green-600" />
    <span x-text="message"></span>
</div>
