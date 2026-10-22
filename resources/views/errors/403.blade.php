@extends('errors.layout')

{{-- Not allowed. When the app gave a reason (abort(403, '…')), that reason is shown instead. --}}
@php
    $reason = isset($exception) ? trim((string) $exception->getMessage()) : '';
    $reason = in_array($reason, ['', 'Forbidden', 'This action is unauthorized.'], true) ? null : $reason;
@endphp

@section('code', '403')
@section('title', 'Not for your role.')
@section('message', $reason ?? 'Ask your Super Admin if you need access.')
