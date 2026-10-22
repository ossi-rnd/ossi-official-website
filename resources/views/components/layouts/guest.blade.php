@props(['title', 'subtitle' => null])

{{-- Sign-in, register, password reset, 2FA: a centred card, same look as the old portal. --}}
<x-layouts.base :title="$title">
    <main class="flex min-h-screen items-center justify-center px-4 py-12">
        <div class="w-full max-w-sm">
            <img src="{{ asset('assets/ossi-seal.png') }}" alt="OSSI logo" class="mx-auto h-16 w-16 object-contain">
            <h1 class="mt-4 text-center text-xl font-semibold text-gray-800 dark:text-gray-100">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-1 text-center text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
            @endif

            @if (session('status'))
                <x-ui.alert tone="blue" class="mt-6">{{ session('status') }}</x-ui.alert>
            @endif

            <div class="ui-card mt-6 p-6">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="mt-6 space-y-2 text-center text-sm text-gray-500 dark:text-gray-400">{{ $footer }}</div>
            @endisset

            <p class="mt-6 text-center text-xs text-gray-400">
                <a href="{{ route('privacy-notice') }}" class="hover:text-gray-600 hover:underline">Privacy Notice</a>
            </p>
        </div>
    </main>
</x-layouts.base>
