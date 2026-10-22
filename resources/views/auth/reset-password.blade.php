<x-layouts.guest title="Set a new password">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-ui.input label="Email" name="email" type="email" :value="old('email', $request->string('email')->toString())" required autocomplete="username" />
        <x-ui.input label="New password" name="password" type="password" required autofocus autocomplete="new-password" />
        <x-ui.input label="Confirm new password" name="password_confirmation" type="password" required autocomplete="new-password" />

        <x-ui.button class="w-full">Reset password</x-ui.button>
    </form>
</x-layouts.guest>
