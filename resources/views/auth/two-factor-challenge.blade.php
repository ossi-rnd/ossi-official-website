<x-layouts.guest title="Two-factor authentication" subtitle="Enter the 6-digit code from your authenticator app.">
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4">
            @csrf

            <div x-show="!recovery">
                <x-ui.input label="Authentication code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus x-bind:disabled="recovery" />
            </div>

            <div x-show="recovery" x-cloak>
                <x-ui.input label="Recovery code" name="recovery_code" autocomplete="off" x-bind:disabled="!recovery" />
            </div>

            <x-ui.button class="w-full">Continue</x-ui.button>
        </form>

        <button type="button" x-on:click="recovery = !recovery" class="mt-4 w-full text-center text-sm font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
            <span x-show="!recovery">Lost your phone? Use a recovery code</span>
            <span x-show="recovery" x-cloak>Use an authentication code</span>
        </button>
    </div>
</x-layouts.guest>
