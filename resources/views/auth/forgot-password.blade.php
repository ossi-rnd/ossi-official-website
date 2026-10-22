<x-layouts.guest title="Forgot your password?" subtitle="Enter your email and we'll send you a reset link.">
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Email" name="email" type="email" :value="old('email')" required autofocus autocomplete="email" />

        <x-ui.button class="w-full">Email reset link</x-ui.button>
    </form>

    <x-slot:footer>
        <p><a href="{{ route('login') }}" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Back to log in</a></p>
    </x-slot:footer>
</x-layouts.guest>
