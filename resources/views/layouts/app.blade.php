{{--
    Livewire's page layout (config livewire.component_layout = 'layouts::app').
    It only forwards to the shared shell, so Livewire pages and plain Blade
    pages (Account Settings) look identical. #[Title('...')] fills $title.
--}}
<x-layouts.app :title="$title ?? null">
    {{ $slot }}
</x-layouts.app>
