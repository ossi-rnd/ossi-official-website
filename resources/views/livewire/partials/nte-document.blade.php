{{--
    A Notice to Explain as both sides read it: the notice, the explanation, the hearing,
    and the decision. $notice: NoticeToExplain; $own: the employee is reading (wording).
--}}
@php
    $own ??= false;
    $label = fn (string $text) => '<p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">'.$text.'</p>';
@endphp

<div class="space-y-4 text-sm">
    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $notice->subject }}</p>
            <p class="text-xs text-gray-500">Issued {{ $notice->created_at->format('M j, Y') }} by {{ $notice->issuer?->name ?? '—' }}</p>
        </div>
        <dl class="mt-3 space-y-3">
            <div>{!! $label('What happened · '.$notice->incident_date->format('M j, Y')) !!}<dd class="mt-0.5 whitespace-pre-line text-gray-800 dark:text-gray-100">{{ $notice->details }}</dd></div>
            <div>{!! $label('The rule it breaks') !!}<dd class="mt-0.5 whitespace-pre-line text-gray-800 dark:text-gray-100">{{ $notice->rule_violated }}</dd></div>
            <div>{!! $label('It could lead to') !!}<dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $notice->possible_penalty->label() }}</dd></div>
            <div>{!! $label('Explain in writing by') !!}<dd class="mt-0.5 text-gray-800 dark:text-gray-100">{{ $notice->explain_by->format('l, M j, Y') }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-gray-500">
            @if ($notice->acknowledged_at) Received {{ $notice->acknowledged_at->format('M j, g:i A') }}. @else Not yet marked received. @endif
        </p>
    </div>

    @if ($notice->explanation)
        <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]">
            {!! $label(($own ? 'Your explanation' : 'Their explanation').' · '.$notice->explained_at?->format('M j, g:i A')) !!}
            <p class="mt-1 whitespace-pre-line text-gray-800 dark:text-gray-100">{{ $notice->explanation }}</p>
            @if ($notice->wants_hearing)<p class="mt-2 text-xs text-gray-500">{{ $own ? 'You asked' : 'They asked' }} for a hearing.</p>@endif
        </div>
    @elseif (! $notice->isDecided() && $notice->readyToDecide())
        <p class="flex items-center gap-1.5 text-xs text-gray-500"><span class="size-1.5 rounded-full bg-amber-500"></span>No explanation by {{ $notice->explain_by->format('M j') }}.</p>
    @endif

    @if ($notice->hearing_at)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
            {!! $label('Hearing') !!}
            <p class="mt-1 text-gray-800 dark:text-gray-100">{{ $notice->hearing_at->format('l, M j, Y · g:i A') }} · {{ $notice->hearing_place }}</p>
        </div>
    @endif

    @if ($notice->isDecided())
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-2">
                {!! $label('Decision · '.$notice->decided_at?->format('M j, Y').' by '.($notice->decider?->name ?? '—')) !!}
                <x-ui.badge :tone="$notice->decision?->tone() ?? 'gray'">{{ $notice->decision?->label() }}</x-ui.badge>
            </div>
            @if ($notice->decision === \App\Enums\NteOutcome::Suspension)
                <p class="mt-2 font-medium text-gray-800 dark:text-gray-100">{{ $notice->suspension_days }} {{ str('day')->plural((int) $notice->suspension_days) }}, from {{ $notice->suspension_from?->format('M j, Y') }}</p>
            @endif
            <p class="mt-2 whitespace-pre-line text-gray-800 dark:text-gray-100">{{ $notice->decision_reason }}</p>
            @if ($notice->demerit)<p class="mt-2 text-xs text-gray-500">With a demerit: {{ $notice->demerit->title }} ({{ $notice->demerit->points }}).</p>@endif
            <p class="mt-2 text-xs text-gray-500">@if ($notice->decision_acknowledged_at) Decision received {{ $notice->decision_acknowledged_at->format('M j, g:i A') }}. @else Decision not yet marked received. @endif</p>
        </div>
    @endif
</div>
