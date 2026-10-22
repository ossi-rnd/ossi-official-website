@extends('errors.layout')

{{-- The form's security token expired: usually a page left open for a long time, or signed out in another tab. --}}
@section('code', '419')
@section('title', 'This page expired.')
@section('message', 'Go back, refresh, and try again.')

@section('actions')
    <a href="{{ url('/login') }}" class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg px-5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10">Sign in again</a>
    <button type="button" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/home') }}')" class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-5 text-sm font-medium text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500">
        <x-icon name="chevron-left" class="h-4 w-4" /> Go back and refresh
    </button>
@endsection
