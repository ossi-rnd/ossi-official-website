{{--
    A daily rate in a Daily Rates table. ₱0 means no rate yet ("Not set").
    `muted`: only a fallback, not a rate Accounting set.
--}}
@if ($amount <= 0)
    <span class="font-medium text-amber-600 dark:text-amber-400">Not set</span>
@else
    <span @class(['font-medium tabular-nums', 'text-gray-400' => $muted])>{{ $peso($amount) }}</span>
@endif