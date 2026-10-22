@extends('errors.layout')

{{-- A rate limit (sign-in attempts, filing, keep-alive). Retry-After says how long; the button counts down. --}}
@php
    $wait = (int) (isset($exception) && method_exists($exception, 'getHeaders') ? ($exception->getHeaders()['Retry-After'] ?? 60) : 60);
    $wait = max(1, min($wait, 3600));
@endphp

@section('code', '429')
@section('title', 'Slow down a little.')
@section('message')
    Too many tries. Wait
    <span class="font-medium text-gray-800 tabular-nums dark:text-gray-100" data-countdown>{{ $wait >= 60 ? ceil($wait / 60).' '.str('minute')->plural((int) ceil($wait / 60)) : $wait.' seconds' }}</span>.
@endsection

@section('actions')
    <a href="{{ url('/home') }}" class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg px-5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10">Take me home</a>
    <button type="button" data-retry disabled onclick="history.length > 1 ? history.back() : location.reload()" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-blue-600 px-5 text-sm font-medium text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-60">
        Try again <span class="ml-1 tabular-nums" data-retry-left>({{ $wait }}s)</span>
    </button>
@endsection

@push('scripts')
    <script>
        (() => {
            let left = {{ $wait }};
            const button = document.querySelector('[data-retry]');
            const label = document.querySelector('[data-retry-left]');
            const text = document.querySelector('[data-countdown]');
            const tick = () => {
                if (left <= 0) {
                    button.disabled = false;
                    label.textContent = '';
                    text.textContent = 'a moment';
                    return;
                }
                label.textContent = '(' + left + 's)';
                text.textContent = left >= 60 ? Math.ceil(left / 60) + ' minute' + (Math.ceil(left / 60) === 1 ? '' : 's') : left + ' second' + (left === 1 ? '' : 's');
                left--;
                setTimeout(tick, 1000);
            };
            tick();
        })();
    </script>
@endpush    
