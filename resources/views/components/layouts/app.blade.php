@props(['title' => null])

@php
    /** @var \App\Models\User $user */
    $user = auth()->user();
    $sections = \App\Support\Navigation::for($user);
    $primaryRole = $user->primaryRole();
    $linkClasses = fn (bool $active) => $active
        ? 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400'
        : 'text-gray-600 hover:bg-gray-50 hover:text-gray-800 dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white';
@endphp

<x-layouts.base :title="$title" x-data="{ sidebarOpen: false, dark: document.documentElement.classList.contains('dark') }"
    x-init="$watch('dark', value => { document.documentElement.classList.toggle('dark', value); localStorage.theme = value ? 'dark' : 'light' })">

    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-blue-600 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white">
        Skip to content
    </a>

    <div class="min-h-screen">
        {{-- Mobile backdrop --}}
        <div x-show="sidebarOpen" x-cloak x-on:click="sidebarOpen = false" x-on:keydown.escape.window="sidebarOpen = false"
            class="fixed inset-0 z-30 bg-gray-900/50 lg:hidden" aria-hidden="true"></div>

        {{-- Sidebar: fixed to the viewport, slides in on mobile --}}
        <aside class="fixed inset-y-0 left-0 z-40 flex h-screen w-64 -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform duration-200 ease-in-out lg:translate-x-0 dark:border-gray-800 dark:bg-gray-900"
            x-bind:class="sidebarOpen && 'translate-x-0!'">
            <div class="flex h-16 shrink-0 items-center gap-2.5 px-6">
                <img src="{{ asset('assets/ossi-seal.png') }}" alt="" class="h-10 w-10 shrink-0 object-contain">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-gray-800 dark:text-gray-100">{{ config('app.name') }}</p>
                    {{-- What OSSI stands for. Wraps rather than being cut short on the narrow sidebar. --}}
                    <p class="text-[11px] leading-tight text-gray-500 dark:text-gray-400">Oriental Sentinel Services Inc.</p>
                </div>
            </div>

            <nav aria-label="Main navigation" class="mt-2 flex-1 space-y-4 overflow-y-auto px-3 pb-4">
                @foreach ($sections as $section)
                    <div>
                        <p class="px-3 pb-1 text-[11px] font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $section['title'] }}</p>
                        <div class="space-y-1">
                            @foreach ($section['items'] as $item)
                                @php $active = request()->routeIs($item['active']); @endphp
                                <a href="{{ route($item['route']) }}" wire:navigate @if ($active) aria-current="page" @endif
                                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium {{ $linkClasses($active) }}">
                                    <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                                    <span class="truncate">{{ $item['label'] }}</span>
                                    @if ($item['badge'] > 0)
                                        <span class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] leading-none font-semibold text-white">
                                            {{ $item['badge'] }}<span class="sr-only"> pending</span>
                                        </span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>

            <div class="shrink-0 space-y-1 border-t border-gray-200 px-3 py-3 dark:border-gray-800">
                <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium {{ $linkClasses(request()->routeIs('profile')) }}">
                    <x-icon name="user" class="h-5 w-5 shrink-0" />
                    <span class="truncate">My Profile</span>
                </a>
                <a href="{{ route('account-settings') }}" wire:navigate class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium {{ $linkClasses(request()->routeIs('account-settings')) }}">
                    <x-icon name="cog" class="h-5 w-5 shrink-0" />
                    <span class="truncate">Account Settings</span>
                </a>
                <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-gray-100 pt-1 dark:border-gray-800">
                    @csrf
                    {{-- Neutral like the items above it (signing out loses nothing); red only on hover. --}}
                    <button type="submit" class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-gray-600 hover:bg-red-50 hover:text-red-600 dark:text-gray-300 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                        <x-icon name="logout" class="h-5 w-5 shrink-0" />
                        <span class="truncate">Log out</span>
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex min-h-screen flex-col lg:ml-64">
            {{-- Top bar --}}
            <header class="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-gray-200 bg-white px-3 sm:gap-3 sm:px-6 dark:border-gray-800 dark:bg-gray-900">
                <button type="button" x-on:click="sidebarOpen = true" aria-label="Open menu"
                    class="shrink-0 rounded-md p-1.5 text-gray-500 hover:bg-gray-50 hover:text-gray-700 lg:hidden dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-100">
                    <x-icon name="menu" class="h-6 w-6" />
                </button>

                <h1 class="flex-1 truncate text-base font-semibold text-gray-800 dark:text-gray-100">{{ $title ?? 'Dashboard' }}</h1>

                <div class="flex shrink-0 items-center gap-1 sm:gap-2">
                    <button type="button" x-on:click="dark = !dark" aria-label="Toggle dark mode" x-bind:aria-pressed="dark.toString()"
                        class="rounded-md p-2 text-gray-500 hover:bg-gray-50 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200">
                        <x-icon name="moon" class="h-5 w-5" x-show="!dark" />
                        <x-icon name="sun" class="h-5 w-5" x-show="dark" x-cloak />
                    </button>

                    <livewire:notification-bell />

                    <div class="relative ml-1" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                        <button type="button" x-on:click="open = !open" aria-haspopup="true" x-bind:aria-expanded="open.toString()" aria-label="Account menu for {{ $user->name }}"
                            class="flex items-center gap-2 rounded-md py-1 pr-2 pl-1 hover:bg-gray-50 dark:hover:bg-white/5">
                            <x-ui.avatar :user="$user" size="sm" />
                            <span class="hidden text-left sm:block">
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ $user->name }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $primaryRole?->label() ?? 'Employee' }}</span>
                            </span>
                            <x-icon name="chevron-down" class="hidden h-4 w-4 text-gray-400 transition-transform duration-200 sm:block" x-bind:class="open && 'rotate-180'" />
                        </button>

                        <div x-show="open" x-cloak x-transition role="menu"
                            class="absolute right-0 z-30 mt-2 w-52 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                                <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ $user->name }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $user->email }}</p>
                            </div>
                            <a href="{{ route('profile') }}" wire:navigate role="menuitem" class="block px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5">My Profile</a>
                            <a href="{{ route('account-settings') }}" wire:navigate role="menuitem" class="block px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5">Account Settings</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" role="menuitem" class="block w-full border-t border-gray-100 px-4 py-2.5 text-left text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-red-600 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-red-500/10 dark:hover:text-red-400">Log out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main id="main-content" class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                @if (session('status'))
                    <x-ui.alert tone="green" class="mb-6">{{ session('status') }}</x-ui.alert>
                @endif

                {{-- Not above the Announcements page itself: it would repeat the list below. --}}
                @unless (request()->routeIs('news'))
                    <x-announcement-banner :user="$user" />
                @endunless

                {{ $slot }}
            </main>
        </div>
    </div>

    @include('partials.idle-timeout')
</x-layouts.base>
