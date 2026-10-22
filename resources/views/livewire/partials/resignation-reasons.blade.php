{{-- The resignation reasons checklist, for the employee's form and HR's paper letter
     (state: ChoosesResignationReasons). $idPrefix: unique ids; $self: the employee ("you")
     or HR entering it ("they"); $number: the step number, if the form numbers its steps;
     $wide: three columns from sm up (the full-width page, not a dialog).
     Labor Code Art. 300: (a) personal reasons need 30 days' notice; (b) a just cause doesn't,
     so the just causes stay folded behind one question. --}}
@php
    $ticked = $reasons ?? [];
    $tile = 'flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:border-gray-300 has-checked:border-blue-500 has-checked:bg-blue-50 dark:border-gray-700 dark:text-gray-200 dark:hover:border-gray-600 dark:has-checked:border-blue-500 dark:has-checked:bg-blue-500/10';
    $box = 'h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800';
@endphp

<fieldset class="space-y-4">
    <legend class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
        @isset($number)<span class="flex h-5 w-5 items-center justify-center rounded-full bg-gray-100 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $number }}</span>@endisset
        {{ $self ? 'Why you\'re leaving' : 'Why they\'re leaving' }}
        <span class="font-normal text-gray-500 dark:text-gray-400">· tick all that apply</span>
    </legend>

    {{-- Two columns even on a phone (the labels are short); three on the full-width page, not in HR's dialog. --}}
    <div @class(['grid grid-cols-2 gap-2', 'sm:grid-cols-3' => $wide ?? false])>
        @foreach (\App\Enums\ResignationReason::personal() as $option)
            <label for="{{ $idPrefix }}-reason-{{ $option->value }}" class="{{ $tile }}">
                <input id="{{ $idPrefix }}-reason-{{ $option->value }}" type="checkbox" wire:model.live="reasons" value="{{ $option->value }}" class="{{ $box }}">
                {{ $option->label() }}
            </label>
        @endforeach
    </div>

    @if (in_array('others', $ticked, true))
        <x-ui.input :id="$idPrefix.'-reason-other'" label="The other reason" wire:model="reasonOther" maxlength="255" />
    @endif

    {{-- A just cause is a serious claim against the company: one question first, the law's list only if it applies. --}}
    <div class="rounded-lg border border-gray-200 dark:border-gray-700">
        <label for="{{ $idPrefix }}-just-cause" class="flex cursor-pointer items-start gap-3 p-4">
            <input id="{{ $idPrefix }}-just-cause" type="checkbox" wire:model.live="claimsJustCause" class="{{ $box }} mt-0.5">
            <span>
                <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ $self ? 'The company, or someone acting for it, did something to me that makes me leave at once' : 'They leave because of something the company, or someone acting for it, did to them' }}</span>
                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">A just cause (Labor Code Art. 300(b)): no 30 days' notice needed. Most resignations aren't this.</span>
            </span>
        </label>

        @if ($claimsJustCause)
            <div class="space-y-2 border-t border-gray-200 px-4 pt-3 pb-4 dark:border-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400">Which one? Tick all that apply.</p>
                @foreach (\App\Enums\ResignationReason::justCauses() as $option)
                    <label for="{{ $idPrefix }}-reason-{{ $option->value }}" class="{{ $tile }} items-start">
                        <input id="{{ $idPrefix }}-reason-{{ $option->value }}" type="checkbox" wire:model.live="reasons" value="{{ $option->value }}" class="{{ $box }} mt-0.5">
                        <span>{{ $self ? $option->label() : $option->labelForOthers() }} <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $option->article() }}</span></span>
                    </label>
                @endforeach
                @if ($this->justCauseTicked())
                    <x-ui.alert tone="amber" class="mt-3">{{ $self ? 'You may leave at once, without the 30 days. In your letter, say what happened: when, where and who.' : 'No notice is needed. Their letter should say what happened: when, where and who.' }}</x-ui.alert>
                @endif
            </div>
        @endif
    </div>

    @error('reasons') <x-ui.alert tone="red">{{ $message }}</x-ui.alert> @enderror
    @error('reasons.*') <x-ui.alert tone="red">Choose from the list.</x-ui.alert> @enderror

    {{-- The law itself, for anyone who wants to read it. --}}
    <details class="group text-sm">
        <summary class="inline-flex cursor-pointer list-none items-center gap-1 text-xs font-medium text-gray-600 select-none hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 [&::-webkit-details-marker]:hidden">
            Read the law: Labor Code Art. 300
            <x-icon name="chevron-down" class="h-3.5 w-3.5 text-gray-400 transition-transform group-open:rotate-180" />
        </summary>
        <div class="mt-2 space-y-2 rounded-lg bg-gray-50 px-4 py-3 text-xs leading-relaxed text-gray-600 dark:bg-gray-800/60 dark:text-gray-300">
            <p class="font-semibold text-gray-700 dark:text-gray-200">Art. 300. Termination by employee.</p>
            <p>(a) An employee may terminate without just cause the employee-employer relationship by serving a written notice on the employer at least one (1) month in advance. The employer upon whom no such notice was served may hold the employee liable for damages.</p>
            <p>(b) An employee may put an end to the relationship without serving any notice on the employer for any of the following just causes:</p>
            <ol class="list-decimal space-y-1 pl-5">
                <li>Serious insult by the employer or his representative on the honor and person of the employee;</li>
                <li>Inhuman and unbearable treatment accorded the employee by the employer or his representative;</li>
                <li>Commission of a crime or offense by the employer or his representative against the person of the employee or any of the immediate members of his family; and</li>
                <li>Other causes analogous to any of the foregoing.</li>
            </ol>
            <p class="pt-1 text-gray-500 dark:text-gray-400">Labor Code of the Philippines (P.D. 442), Art. 300, formerly Art. 285, renumbered by DOLE Department Advisory No. 01, s. 2015.</p>
        </div>
    </details>
</fieldset>
