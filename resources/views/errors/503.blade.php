@extends('errors.layout')

{{-- `php artisan down` (an update in progress). The page checks again by itself every 30 seconds. --}}
@section('code', '503')
@section('title', 'Back in a few minutes.')
@section('message', "We're updating the app. This page checks by itself.")

@section('actions')
    <button type="button" onclick="location.reload()" class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-5 text-sm font-medium text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500">
        <x-icon name="arrow-path" class="h-4 w-4" /> Check now
    </button>
@endsection

@section('help', 'Clocking in or out? Note the time and tell your supervisor; it can be added once the app is back.')

@push('scripts')
    <script>setTimeout(() => location.reload(), 30000);</script>
@endpush
