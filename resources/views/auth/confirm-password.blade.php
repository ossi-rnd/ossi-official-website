<x-layouts.guest title="Confirm your password" subtitle="This is a secure area. Please confirm your password to continue.">
    <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Password" name="password" type="password" required autofocus autocomplete="current-password" />

        <x-ui.button class="w-full">Confirm</x-ui.button>
    </form>
</x-layouts.guest>
