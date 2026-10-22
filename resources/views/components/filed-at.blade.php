@props(['at'])

{{-- When a request was filed: the date, with the exact time underneath. Same on every request table. --}}
@php /** @var \Carbon\CarbonInterface $at */ @endphp

<time datetime="{{ $at->toIso8601String() }}" title="{{ $at->diffForHumans() }}" {{ $attributes->class('whitespace-nowrap') }}>
    {{ $at->format('M j, Y') }}
    <span class="block text-xs text-gray-400">{{ $at->format('g:i A') }}</span>
</time>
