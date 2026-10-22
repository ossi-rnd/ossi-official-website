@extends('errors.layout')

@section('code', '401')
@section('title', 'Sign in first.')
@section('message', 'You need to be signed in to see this.')

@section('actions')
    <a href="{{ url('/login') }}" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-blue-600 px-5 text-sm font-medium text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500">Sign in</a>
@endsection
