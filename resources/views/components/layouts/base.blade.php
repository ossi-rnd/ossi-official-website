@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        <link rel="icon" type="image/png" href="{{ asset('assets/ossi-seal.png') }}">

        {{-- Before CSS loads, so a dark-mode user never sees a white flash. wire:navigate swaps in the
             next page's <html> attributes (no "dark" class) and doesn't re-run this script, so the theme is
             applied again on every swap, before Alpine starts and the toggle reads it. --}}
        <script>
            window.applyTheme = () => {
                let dark = false;
                try { dark = localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches); } catch (e) {}
                document.documentElement.classList.toggle('dark', dark);
            };
            window.applyTheme();
            document.addEventListener('livewire:navigating', (event) => event.detail.onSwap(window.applyTheme));
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body {{ $attributes->class('bg-gray-50 text-gray-800 antialiased dark:bg-gray-950 dark:text-gray-100') }}>
        {{ $slot }}

        <x-toast />

        @livewireScripts
    </body>
</html>
