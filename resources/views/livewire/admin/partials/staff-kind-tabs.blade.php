{{--
    Office staff / Guards tabs, for those who see both (GroupsByStaffKind).

    A click moves the underline at once, in the browser (Alpine `shown`), and a thin bar runs
    under the tabs while the server sends the other list, which the page dims meanwhile
    (wire:target="showGroup"). Before, nothing moved until the reply came, which over a
    slow line felt like the click hadn't registered. Livewire's re-render then confirms it.
--}}
@if ($this->hasGroups())
    @php $counts = $this->groupCounts; $shown = $this->shownGroup(); @endphp
    <div class="relative" x-data="{ shown: @js($shown) }">
        <div class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-gray-200 px-4 dark:border-gray-800" role="tablist" aria-label="Whose requests">
            @foreach (['office' => ['Office staff', 'yours to decide'], 'field' => ['Guards', 'monitor only']] as $value => [$label, $hint])
                <button type="button" role="tab" wire:click="showGroup('{{ $value }}')" x-on:click="shown = '{{ $value }}'"
                    aria-selected="{{ $shown === $value ? 'true' : 'false' }}" x-bind:aria-selected="(shown === '{{ $value }}').toString()"
                    {{-- Colours only from Alpine (object syntax adds AND removes them): drawn statically too,
                         the server's "selected" colours would stay on the old tab until the reply. --}}
                    class="-mb-px inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors"
                    x-bind:class="{ 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400': shown === '{{ $value }}', 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300': shown !== '{{ $value }}' }">
                    {{ $label }}
                    <span @class(['rounded-full px-1.5 text-xs', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' => $value === 'office' && $counts[$value] > 0, 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => ! ($value === 'office' && $counts[$value] > 0)])>{{ $counts[$value] }}</span>
                    <span class="hidden text-xs font-normal text-gray-400 sm:inline">· {{ $hint }}</span>
                </button>
            @endforeach
        </div>
        {{-- Loading: a thin bar sliding along the bottom edge of the tabs. --}}
        <div wire:loading.delay.shortest wire:target="showGroup" class="absolute inset-x-0 bottom-0 h-0.5 overflow-hidden" aria-hidden="true">
            <div class="tab-loading-bar h-full w-1/3 bg-blue-500"></div>
        </div>
    </div>
@endif
