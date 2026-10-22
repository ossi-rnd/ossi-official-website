{{-- What the employee confirms before sending: a short list, then a required tick. Kept short and
     specific so it gets read; the Action saves the lines word for word with the time, in the
     language they read (ChoosesAcknowledgementLanguage: $ackLocale, English or Filipino).
     $lines: list<string>; $model: the Livewire property; $id: the checkbox id;
     $labelKey: optional, acknowledgements.ui.* ("read" by default, or "certify"). --}}
<div class="space-y-3">
    @include('livewire.partials.acknowledgement-language')
    <ul class="list-disc space-y-2 rounded-lg border border-gray-200 py-3 pr-4 pl-8 text-sm text-gray-700 marker:text-gray-400 dark:border-gray-700 dark:text-gray-300" lang="{{ $ackLocale }}">
        @foreach ($lines as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
    <x-ui.checkbox :id="$id" wire:model="{{ $model }}" :label="\App\Support\Acknowledgements::line('ui.'.($labelKey ?? 'read'), $ackLocale)" />
    @error($model) <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror
</div>
