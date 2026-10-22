@extends('errors.layout')

{{-- Something broke on our side. The time is shown so IT can find it in the logs; no details leak. --}}
@section('code', '500')
@section('title', 'Something broke.')
@section('message', "Not your fault. We're on it, so try again shortly.")

@section('actions')
    <a href="{{ url('/home') }}" class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg px-5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10">Take me home</a>
    <button type="button" onclick="location.reload()" class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-5 text-sm font-medium text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500">
        <x-icon name="arrow-path" class="h-4 w-4" /> Try again
    </button>
@endsection

@section('help')
    If it keeps happening, tell the IT team the time: <span class="font-medium text-gray-700 tabular-nums dark:text-gray-300">{{ now()->format('M j, Y g:i:s A') }}</span>.
@endsection
