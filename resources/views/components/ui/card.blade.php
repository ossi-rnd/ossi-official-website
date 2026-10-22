@props(['title' => null, 'description' => null])

{{-- A white panel with an optional header row: title, description, and an `actions` slot. --}}
<section {{ $attributes->class('ui-card') }}>
    @if ($title || isset($actions))
        <div class="ui-card-header">
            <div>
                @if ($title)
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    {{ $slot }}

    @isset($footer)
        <div class="border-t border-gray-200 p-4 dark:border-gray-800">{{ $footer }}</div>
    @endisset
</section>
