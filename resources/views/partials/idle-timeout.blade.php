{{--
    The idle countdown. Only the warning UI and clock sync: the sign-out is
    enforced server-side by EnforceIdleTimeout. See resources/js/idle-timeout.js.
--}}
@if (config('security.idle_timeout.enabled'))
    @php
        $timeoutSeconds = max(1, (int) config('security.idle_timeout.minutes')) * 60;
        // A warning longer than the budget would show the dialog on page load.
        $warningSeconds = min(max(10, (int) config('security.idle_timeout.warning_seconds')), $timeoutSeconds - 5);
    @endphp

    <div x-data="idleTimeout({ timeoutSeconds: {{ $timeoutSeconds }}, warningSeconds: {{ $warningSeconds }}, keepAliveUrl: @js(route('session.keep-alive')), loginUrl: @js(route('login')) })">
        <form x-ref="logoutForm" method="POST" action="{{ route('logout') }}" class="hidden">
            @csrf
            <input type="hidden" name="reason" value="idle">
        </form>

        <div x-show="warningVisible" x-cloak role="alertdialog" aria-modal="true" aria-labelledby="idle-title" aria-describedby="idle-description"
            x-on:keydown.escape.window="warningVisible && staySignedIn()"
            class="fixed inset-0 z-[70] flex items-center justify-center bg-gray-900/60 px-4">
            <div class="w-full max-w-sm rounded-xl border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-700 dark:bg-gray-800">
                <h2 id="idle-title" class="text-base font-semibold text-gray-800 dark:text-gray-100">Still there?</h2>
                <p id="idle-description" class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    You've been inactive for a while. For security you'll be signed out in
                    <span class="font-semibold text-gray-800 tabular-nums dark:text-gray-100" x-text="countdownLabel"></span>.
                </p>
                <p class="sr-only" aria-live="assertive" x-text="`Signing out in ${secondsLeft} seconds.`"></p>

                <div class="mt-5 flex gap-3">
                    <button type="button" x-on:click="staySignedIn()" x-ref="stay" x-effect="warningVisible && $nextTick(() => $refs.stay.focus())"
                        class="flex-1 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-2 focus:outline-none">
                        Stay signed in
                    </button>
                    <button type="button" x-on:click="signOut()"
                        class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-white/5">
                        Log out now
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
