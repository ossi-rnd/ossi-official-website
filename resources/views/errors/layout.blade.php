{{--
    Shared shell for error pages (401, 403, 404, 419, 429, 500, 503). Simple and modern: no card,
    the big status number is the picture (gray, fading down, never coloured text), a soft blue glow
    and a faint grid behind it, the OSSI seal in a slim header. Light or dark like the rest of the app.

    No auth, session or database: these render even when those are what broke. Sections:
      code, title, message: required;
      actions: the buttons (default: Go back, and back to the dashboard);
      help: a line at the bottom (default: who to ask).
--}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex">
        <title>@yield('title') · {{ config('app.name') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('assets/ossi-seal.png') }}">
        {{-- The saved theme, before the CSS, so a dark-mode user never sees a white flash. --}}
        <script>
            try { document.documentElement.classList.toggle('dark', localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)); } catch (e) {}
        </script>
        @vite(['resources/css/app.css'])
    </head>
    <body class="relative min-h-screen bg-white text-gray-800 antialiased dark:bg-gray-950 dark:text-gray-100">
        {{-- Background: a faint grid fading out from the middle, and a soft glow behind the number. --}}
        {{-- Clipped, so the glow never makes a phone scroll sideways. --}}
        <div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
            <div class="absolute inset-0 bg-[linear-gradient(to_right,rgb(0_0_0/0.04)_1px,transparent_1px),linear-gradient(to_bottom,rgb(0_0_0/0.04)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_70%)] bg-[size:48px_48px] dark:bg-[linear-gradient(to_right,rgb(255_255_255/0.05)_1px,transparent_1px),linear-gradient(to_bottom,rgb(255_255_255/0.05)_1px,transparent_1px)]"></div>
            <div class="absolute top-1/3 left-1/2 h-[28rem] w-[28rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-blue-500/10 blur-3xl dark:bg-blue-500/15"></div>
        </div>

        <div class="relative flex min-h-screen flex-col">
            <header class="flex items-center gap-2.5 px-6 py-5">
                <img src="{{ asset('assets/ossi-seal.png') }}" alt="" class="h-8 w-8 object-contain">
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">OSSI</span>
            </header>

            <main class="flex flex-1 items-center justify-center px-6 pb-16">
                <div class="w-full max-w-lg text-center">
                    {{-- The number is the picture: huge, gray, fading toward the bottom. --}}
                    <p class="bg-gradient-to-b from-gray-300 to-gray-100 bg-clip-text text-[7rem] leading-none font-black tracking-tighter text-transparent tabular-nums select-none sm:text-[9rem] dark:from-gray-600 dark:to-gray-900" aria-hidden="true">@yield('code')</p>

                    {{-- Big and short (2–4 words), tucked into the bottom of the number; then one quiet line. --}}
                    <h1 class="relative -mt-8 text-4xl font-bold tracking-tight text-gray-900 sm:-mt-12 sm:text-5xl dark:text-white">@yield('title')</h1>
                    <p class="mx-auto mt-4 max-w-sm text-sm leading-relaxed text-gray-500 sm:text-base dark:text-gray-400">@yield('message')</p>

                    {{-- One blue button (the way forward), and a quiet way back. --}}
                    <div class="mt-8 flex flex-col-reverse items-stretch justify-center gap-3 sm:flex-row sm:items-center">
                        @section('actions')
                            <button type="button" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/home') }}')" class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg px-5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10">
                                <x-icon name="chevron-left" class="h-4 w-4" /> Go back
                            </button>
                            <a href="{{ url('/home') }}" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-blue-600 px-5 text-sm font-medium text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500">Take me home</a>
                        @show
                    </div>
                </div>
            </main>

            <footer class="px-6 pb-6 text-center text-xs text-gray-400 dark:text-gray-500">
                <span class="sr-only">Error @yield('code').</span>
                @section('help')
                    Still stuck? Tell HR or the IT team what you were doing.
                @show
            </footer>
        </div>
        @stack('scripts')
    </body>
</html>
