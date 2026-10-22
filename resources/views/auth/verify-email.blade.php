<x-layouts.guest title="Confirm your email" subtitle="We sent a confirmation link to your inbox. Click it to finish setting up your account.">
    @if (session('status') === 'verification-link-sent')
        <x-ui.alert tone="green" class="mb-4">A new confirmation link has been sent to {{ auth()->user()?->email }}.</x-ui.alert>
    @endif

    <p class="text-sm text-gray-600 dark:text-gray-300">
        Can't find it? Check your spam folder, or send a new link. Links expire after 60 minutes.
    </p>

    <div class="mt-5 flex items-center justify-between gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button>Resend link</x-ui.button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button variant="ghost">Log out</x-ui.button>
        </form>
    </div>
</x-layouts.guest>
