{{-- For the handler: what the employee confirmed before sending, word for word, folded.
     $record: any model with `acknowledgement` (list<string>|null) and `acknowledged_at`. --}}
@if ($record->acknowledgement)
    <details class="group mt-2 text-sm">
        <summary class="inline-flex cursor-pointer list-none items-center gap-1 text-xs font-medium text-gray-600 select-none hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 [&::-webkit-details-marker]:hidden">
            They read and acknowledged {{ count($record->acknowledgement) }} points · {{ $record->acknowledged_at?->format('M j, Y g:i A') }}
            <x-icon name="chevron-down" class="h-3.5 w-3.5 text-gray-400 transition-transform group-open:rotate-180" />
        </summary>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-gray-600 dark:text-gray-300">
            @foreach ($record->acknowledgement as $line)<li>{{ $line }}</li>@endforeach
        </ul>
    </details>
@endif
