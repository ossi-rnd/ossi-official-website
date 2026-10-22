{{-- English · Filipino: the language the acknowledgement is read in ($ackLocale). --}}
<div class="flex items-center justify-end gap-1 text-xs" role="group" aria-label="Language">
    <span class="mr-1 text-gray-500 dark:text-gray-400">{{ \App\Support\Acknowledgements::line('ui.language', $ackLocale) }}</span>
    @foreach (\App\Support\Acknowledgements::LOCALES as $code => $name)
        <button type="button" wire:click="$set('ackLocale', '{{ $code }}')" aria-pressed="{{ $ackLocale === $code ? 'true' : 'false' }}"
            @class([
                'rounded-md px-2 py-1 font-medium transition-colors',
                'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-100' => $ackLocale === $code,
                'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $ackLocale !== $code,
            ])>{{ $name }}</button>
    @endforeach
</div>
