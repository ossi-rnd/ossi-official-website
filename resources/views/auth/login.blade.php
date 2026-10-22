<x-layouts.guest title="Welcome back" subtitle="Sign in to clock in, file requests and view your payslips.">
    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <x-ui.input label="Email" name="email" type="email" :value="old('email')" required autofocus autocomplete="username" />
        <x-ui.input label="Password" name="password" type="password" required autocomplete="current-password" />

        <div class="flex items-center justify-between text-sm">
            <label class="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                <input type="checkbox" name="remember" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Forgot password?</a>
        </div>

        <x-ui.button class="w-full">Log in</x-ui.button>
    </form>

    <x-slot:footer>
        <p>
            Added by HR but no account yet?
            <a href="{{ route('register') }}" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Create your account</a>
        </p>
    </x-slot:footer>
</x-layouts.guest>
