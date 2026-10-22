@props(['user', 'size' => 'md'])

@php
    $sizes = ['sm' => 'h-8 w-8 text-xs', 'md' => 'h-10 w-10 text-sm', 'lg' => 'h-20 w-20 text-2xl'];
    $classes = $sizes[$size] ?? $sizes['md'];
    $url = $user->avatarUrl();
@endphp

@if ($url)
    <img src="{{ $url }}" alt="" {{ $attributes->class("shrink-0 rounded-full object-cover {$classes}") }}>
@else
    <span aria-hidden="true" {{ $attributes->class("flex shrink-0 items-center justify-center rounded-full bg-blue-100 font-semibold text-blue-700 dark:bg-blue-500/20 dark:text-blue-300 {$classes}") }}>
        {{ $user->initials() }}
    </span>
@endif
