{{--
    Two audiences: a signed-in user held here by RequirePrivacyAcknowledgement
    (shown the acknowledge button), and anyone else simply reading it.
--}}
<x-layouts.base title="Privacy Notice">
    <main class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <header class="border-b border-gray-200 pb-6 dark:border-gray-800">
            <img src="{{ asset('assets/ossi-seal.png') }}" alt="OSSI logo" class="mb-4 h-12 w-12 object-contain">
            <p class="text-xs font-semibold tracking-widest text-blue-600 uppercase dark:text-blue-400">Data Privacy Act (RA 10173)</p>
            <h1 class="mt-2 text-2xl font-semibold text-gray-800 dark:text-gray-100">Privacy Notice</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                How {{ $controller['name'] }} collects, uses and protects your personal information as an employee.
                <span class="whitespace-nowrap">Version {{ $version }}.</span>
            </p>
        </header>

        @if ($needsAcknowledgement)
            {{-- Before the text: someone held here needs to know why straight away. --}}
            <x-ui.alert tone="blue" class="mt-6">
                Please read this notice and confirm at the bottom that you have been informed. You only need to do this once, unless the notice changes.
            </x-ui.alert>
        @endif

        <div class="mt-8 space-y-8">
            @foreach ($sections as $section)
                <section>
                    <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ $section['heading'] }}</h2>
                    <ul class="mt-3 space-y-2">
                        @foreach ($section['body'] as $paragraph)
                            <li class="flex gap-3 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                                <span aria-hidden="true" class="mt-2 h-1 w-1 shrink-0 rounded-full bg-gray-400"></span>
                                <span>{{ $paragraph }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach

            <section>
                <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">Who to contact</h2>
                <dl class="mt-3 space-y-4 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <div>
                        <dt class="font-medium text-gray-800 dark:text-gray-100">Personal Information Controller</dt>
                        <dd>{{ $controller['name'] }}</dd>
                        <dd>{{ $controller['address'] }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-800 dark:text-gray-100">Data Protection Officer</dt>
                        <dd>{{ $officer['name'] }}</dd>
                        @if (! empty($officer['email']))
                            <dd><a href="mailto:{{ $officer['email'] }}" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ $officer['email'] }}</a></dd>
                        @endif
                        @if (! empty($officer['phone']))
                            <dd>{{ $officer['phone'] }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="font-medium text-gray-800 dark:text-gray-100">{{ $commission['name'] }}</dt>
                        <dd class="text-gray-500">You may complain to the Commission directly, whether or not you have contacted us first.</dd>
                        <dd>{{ $commission['address'] }}</dd>
                        <dd><a href="mailto:{{ $commission['email'] }}" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ $commission['email'] }}</a></dd>
                        <dd><a href="{{ $commission['website'] }}" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">{{ $commission['website'] }}</a></dd>
                    </div>
                </dl>
            </section>
        </div>

        @auth
            @if ($needsAcknowledgement)
                <div class="ui-card mt-10 p-6">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">Acknowledgement</h2>
                    {{-- "Read and understood", never "I consent": see config/privacy.php for why. --}}
                    <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                        By continuing you confirm that you have read and understood this notice. This records that you were informed;
                        it is not a consent form, and it does not waive any of the rights described above.
                    </p>
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('privacy-notice.acknowledge') }}">
                            @csrf
                            <x-ui.button>I have read and understood this notice</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-ui.button variant="ghost">Log out instead</x-ui.button>
                        </form>
                    </div>
                </div>
            @else
                <p class="mt-10 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    You acknowledged this notice on {{ auth()->user()->privacy_acknowledged_at?->format('M j, Y g:i A') }} (version {{ auth()->user()->privacy_notice_version }}).
                    <a href="{{ route('home') }}" class="ml-1 font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Back to the app</a>
                </p>
            @endif
        @else
            <p class="mt-10 text-center text-sm text-gray-500">
                <a href="{{ route('login') }}" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Back to sign in</a>
            </p>
        @endauth
    </main>
</x-layouts.base>
