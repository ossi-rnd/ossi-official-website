@props(['amount', 'negative' => false])

{{-- Pesos, always two decimals. `negative` prefixes a minus for deduction lines. --}}
<span {{ $attributes->class('tabular-nums whitespace-nowrap') }}>{{ $negative ? '-' : '' }}₱{{ number_format((float) $amount, 2) }}</span>
