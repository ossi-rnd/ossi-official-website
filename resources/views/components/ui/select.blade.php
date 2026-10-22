{{-- searchable: a filter box at the top of the list, for long lists (e.g. provinces). --}}
@props(['label' => null, 'name' => null, 'searchable' => false])

@php
    $key = $attributes->wire('model')->value() ?: $name;
    $id = $attributes->get('id') ?? str_replace('.', '-', (string) ($key ?: 'select-'.\Illuminate\Support\Str::random(6)));
    $hasError = $key !== null && $errors->has($key);
@endphp

{{-- Styled list over a hidden real <select>, which stays the source of truth for wire:model. --}}
<div>
    @if ($label)
        <label for="{{ $id }}" class="ui-label">{{ $label }}</label>
    @endif

    <div x-data="customSelect(@js((bool) $searchable))" x-on:click.outside="close()" @class(['relative', 'mt-1' => $label])>
        <select x-ref="select" @if ($name) name="{{ $name }}" @endif tabindex="-1" aria-hidden="true" {{ $attributes->except(['id', 'class'])->merge(['class' => 'hidden']) }}>
            {{ $slot }}
        </select>

        <button type="button" id="{{ $id }}" x-ref="button" x-on:click="toggle()" x-on:keydown="onKeydown($event)"
            aria-haspopup="listbox" aria-controls="{{ $id }}-listbox" x-bind:aria-expanded="open.toString()"
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            @class([
                'flex min-h-10 w-full min-w-40 items-center justify-between gap-2 rounded-md border bg-white py-2 pr-2.5 pl-3 text-left text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:bg-gray-800',
                'border-red-500' => $hasError,
                'border-gray-300 dark:border-gray-700' => ! $hasError,
            ])>
            <span x-text="selectedLabel" class="block truncate text-gray-800 dark:text-gray-100"></span>
            <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" x-bind:class="open && 'rotate-180'" />
        </button>

        <div x-show="open" x-cloak x-transition role="listbox" id="{{ $id }}-listbox"
            x-bind:class="dropUp ? 'bottom-full mb-1' : 'top-full mt-1'"
            @class(['absolute z-30 max-h-60 w-full overflow-auto rounded-md border border-gray-200 bg-white text-sm shadow-lg dark:border-gray-700 dark:bg-gray-800', 'py-1' => ! $searchable, 'pb-1' => $searchable])>
            @if ($searchable)
                <div class="sticky top-0 z-10 border-b border-gray-100 bg-white p-2 dark:border-gray-700 dark:bg-gray-800">
                    <input type="search" x-ref="search" x-model="query" x-on:input="onSearch()" x-on:keydown="onKeydown($event)"
                        placeholder="Search…" aria-label="Search the list" aria-controls="{{ $id }}-listbox" autocomplete="off" spellcheck="false"
                        class="block w-full rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <p x-show="shown.length === 0" class="px-3 py-2 text-gray-500 dark:text-gray-400">No match.</p>
            @endif
            <template x-for="(option, index) in shown" :key="option.value + index">
                <div role="option" x-on:click="choose(option)" x-on:mouseenter="highlightedIndex = index"
                    x-bind:aria-selected="(option.value === selectedValue).toString()"
                    x-bind:class="{
                        'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300': index === highlightedIndex,
                        'text-gray-800 dark:text-gray-100': index !== highlightedIndex,
                        'cursor-not-allowed opacity-40': option.disabled,
                        'cursor-pointer': !option.disabled,
                    }"
                    class="flex items-center justify-between gap-2 px-3 py-2">
                    <span x-text="option.label" class="block truncate" x-bind:class="option.value === selectedValue ? 'font-semibold' : ''"></span>
                    <span x-show="option.value === selectedValue" class="h-1.5 w-1.5 shrink-0 rounded-full bg-blue-600" aria-hidden="true"></span>
                </div>
            </template>
        </div>
    </div>

    @if ($hasError)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $errors->first($key) }}</p>
    @endif
</div>
