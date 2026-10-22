<x-layouts.guest title="Create your account" subtitle="For employees HR has already registered. Use the employee number and email you gave HR.">
    <form method="POST" action="{{ route('register.store') }}" class="space-y-4">
        @csrf

        {{-- Capitals, no spaces (as EmployeeNumber::normalize). Not reshaped: an older number (EMP-1001) must still work. --}}
        <x-ui.input label="Employee number" name="employee_number" :value="old('employee_number')" required autofocus autocomplete="off" spellcheck="false" maxlength="20"
            :placeholder="'e.g. '.\App\Support\EmployeeNumber::EXAMPLE" hint="Printed on your ID, or ask HR."
            x-data x-on:input="$el.value = $el.value.toUpperCase().replace(/\s+/g, '')" />
        <x-ui.input label="Email" name="email" type="email" :value="old('email')" required autocomplete="email" hint="Must be the same email HR has on file." />
        <x-ui.input label="Password" name="password" type="password" required autocomplete="new-password" />
        <x-ui.input label="Confirm password" name="password_confirmation" type="password" required autocomplete="new-password" />

        <x-ui.button class="w-full">Create account</x-ui.button>
    </form>

    {{-- Notice at or before collection: RA 10173 sec. 16(a). --}}
    <p class="mt-4 text-center text-xs leading-relaxed text-gray-500 dark:text-gray-400">
        We'll email you a link to confirm your address. Read how we handle your personal information in our
        <a href="{{ route('privacy-notice') }}" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Privacy Notice</a>.
    </p>

    <x-slot:footer>
        <p>Already have an account? <a href="{{ route('login') }}" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Log in</a></p>
    </x-slot:footer>
</x-layouts.guest>
