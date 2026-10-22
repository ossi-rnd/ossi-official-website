{{--
    Account Settings. Each form posts to a Fortify route; Fortify validates,
    saves and redirects back with a `status` flash we show next to the form.
--}}
<x-layouts.app title="Account Settings">
    <div class="mx-auto max-w-3xl space-y-6">
        <livewire:profile.avatar-form />

        {{-- Name & login email --}}
        <x-ui.card title="Profile information" description="Your display name and the email you sign in with. Changing the email sends a new confirmation link.">
            <form method="POST" action="{{ route('user-profile-information.update') }}" class="space-y-4 p-6">
                @csrf
                @method('PUT')

                <x-ui.input label="Full name" name="name" bag="updateProfileInformation" :value="old('name', $user->name)" required autocomplete="name" autocapitalize="words" maxlength="255"
                    x-data x-on:input="$el.value = $el.value.replace(/[^\p{L}\p{M} .,'-]/gu, '')" />
                <x-ui.input label="Email" name="email" type="email" bag="updateProfileInformation" :value="old('email', $user->email)" required autocomplete="email" />

                <div class="flex items-center gap-3">
                    <x-ui.button>Save changes</x-ui.button>
                    @if (session('status') === 'profile-information-updated')
                        <p role="status" class="text-sm text-green-600 dark:text-green-400">Saved.</p>
                    @endif
                </div>
            </form>
        </x-ui.card>

        {{-- Password --}}
        <x-ui.card title="Change password" description="Use a long password you don't use anywhere else.">
            <form method="POST" action="{{ route('user-password.update') }}" class="space-y-4 p-6">
                @csrf
                @method('PUT')

                <x-ui.input label="Current password" name="current_password" type="password" bag="updatePassword" required autocomplete="current-password" />
                <x-ui.input label="New password" name="password" type="password" bag="updatePassword" required autocomplete="new-password" />
                <x-ui.input label="Confirm new password" name="password_confirmation" type="password" bag="updatePassword" required autocomplete="new-password" />

                <div class="flex items-center gap-3">
                    <x-ui.button>Update password</x-ui.button>
                    @if (session('status') === 'password-updated')
                        <p role="status" class="text-sm text-green-600 dark:text-green-400">Password updated.</p>
                    @endif
                </div>
            </form>
        </x-ui.card>

        {{-- Two-factor authentication (Fortify) --}}
        <x-ui.card title="Two-factor authentication" description="Strongly recommended for HR, payroll and admin accounts: a stolen password alone is no longer enough to sign in.">
            <div class="space-y-4 p-6">
                @if (! $user->two_factor_secret)
                    <p class="text-sm text-gray-600 dark:text-gray-300">Two-factor authentication is <strong>off</strong>. You'll need an authenticator app such as Google Authenticator or Microsoft Authenticator.</p>

                    <form method="POST" action="{{ route('two-factor.enable') }}">
                        @csrf
                        <x-ui.button><x-icon name="lock" class="h-4 w-4" /> Turn on</x-ui.button>
                    </form>
                @elseif (! $user->two_factor_confirmed_at)
                    <p class="text-sm text-gray-600 dark:text-gray-300">Scan this QR code with your authenticator app, then enter the 6-digit code it shows.</p>

                    <div class="inline-block rounded-lg bg-white p-3">{!! $user->twoFactorQrCodeSvg() !!}</div>
                    <p class="text-xs text-gray-500">Setup key: <code class="font-mono">{{ decrypt($user->two_factor_secret) }}</code></p>

                    <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        @csrf
                        <div class="sm:w-48">
                            <x-ui.input label="Code" name="code" bag="confirmTwoFactorAuthentication" inputmode="numeric" autocomplete="one-time-code" required />
                        </div>
                        <x-ui.button>Confirm</x-ui.button>
                    </form>
                @else
                    <x-ui.alert tone="green">Two-factor authentication is <strong>on</strong>.</x-ui.alert>

                    @if (in_array(session('status'), ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true))
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Store these recovery codes somewhere safe. Each one signs you in once if you lose your phone.</p>
                            <div class="mt-3 grid max-w-md grid-cols-2 gap-1 rounded-lg bg-gray-100 p-4 font-mono text-sm dark:bg-gray-800">
                                @foreach ($user->recoveryCodes() as $code)
                                    <span>{{ $code }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                            @csrf
                            <x-ui.button variant="secondary">New recovery codes</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('two-factor.disable') }}" onsubmit="return confirm('Turn off two-factor authentication?')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button variant="danger-outline">Turn off</x-ui.button>
                        </form>
                    </div>
                @endif
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
