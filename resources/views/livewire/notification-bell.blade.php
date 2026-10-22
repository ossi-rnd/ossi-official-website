<div class="relative" x-data="liveBell({ userId: {{ auth()->id() }}, unread: {{ $this->unreadCount }} })" data-unread="{{ $this->unreadCount }}"
    x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <button type="button" x-on:click="open = ! open" aria-haspopup="true" x-bind:aria-expanded="open.toString()"
        aria-label="Notifications{{ $this->unreadCount > 0 ? ', '.$this->unreadCount.' unread' : '' }}"
        x-bind:aria-label="unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'"
        class="relative rounded-md p-2 text-gray-500 hover:bg-gray-50 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200">
        <x-icon name="bell" class="h-5 w-5" />
        <span x-show="unread > 0" x-text="badge" @if ($this->unreadCount === 0) style="display: none" @endif
            {{-- On the bell's corner, cut out from it by a ring in the header's colour; a circle up to 9, a pill for "9+". --}}
            class="pointer-events-none absolute top-px right-px flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[9px] leading-none font-bold text-white tabular-nums ring-2 ring-white dark:bg-red-500 dark:ring-gray-900" aria-hidden="true">{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
    </button>

    <div x-show="open" x-cloak x-transition
        class="absolute right-0 z-30 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-700">
            <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Notifications</p>
            <button type="button" x-show="unread > 0" x-cloak wire:click="markAllRead" class="text-xs font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Mark all as read</button>
        </div>

        <ul class="max-h-96 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
            {{-- Arrived while this page was open (live-bell.js). --}}
            <template x-for="item in live" :key="item.id">
                <li>
                    <button type="button" x-on:click="openLive(item.id)" class="flex w-full gap-3 bg-blue-50/60 px-4 py-3 text-left hover:bg-gray-50 dark:bg-blue-500/5 dark:hover:bg-white/5">
                        {{-- The same icon the notification gets after a reload (the icons notifications use). --}}
                        @foreach ($liveIcons as $icon)
                            <template x-if="item.icon === @js($icon)"><x-icon :name="$icon" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" /></template>
                        @endforeach
                        <template x-if="! @js($liveIcons).includes(item.icon)"><x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" /></template>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100" x-text="item.title"></span>
                            <span class="mt-0.5 block text-xs leading-relaxed whitespace-pre-line text-gray-500 dark:text-gray-400" x-text="item.body"></span>
                            <span class="mt-1 block text-[11px] text-gray-400">just now</span>
                        </span>
                        <span class="mt-1.5 ml-auto h-2 w-2 shrink-0 rounded-full bg-blue-600"><span class="sr-only">Unread</span></span>
                    </button>
                </li>
            </template>

            @forelse ($this->recent as $notification)
                <li wire:key="notification-{{ $notification->id }}">
                    <button type="button" wire:click="open('{{ $notification->id }}')"
                        class="flex w-full gap-3 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-white/5 {{ $notification->read_at === null ? 'bg-blue-50/60 dark:bg-blue-500/5' : '' }}">
                        <x-icon :name="$notification->data['icon'] ?? 'info'" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ $notification->data['title'] ?? 'Notification' }}</span>
                            {{-- One fact per line in the body ("\n"), kept on screen. --}}
                            <span class="mt-0.5 block text-xs leading-relaxed whitespace-pre-line text-gray-500 dark:text-gray-400">{{ $notification->data['body'] ?? '' }}</span>
                            <span class="mt-1 block text-[11px] text-gray-400">{{ $notification->created_at?->diffForHumans() }}</span>
                        </span>
                        @if ($notification->read_at === null)
                            <span class="mt-1.5 ml-auto h-2 w-2 shrink-0 rounded-full bg-blue-600"><span class="sr-only">Unread</span></span>
                        @endif
                    </button>
                </li>
            @empty
                <li x-show="live.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">You're all caught up.</li>
            @endforelse
        </ul>
    </div>

    {{-- A short pop-up when one arrives; the bell keeps it after it fades. --}}
    <div x-show="toast" x-cloak x-transition.opacity role="status" aria-live="polite"
        class="fixed right-4 bottom-4 z-50 w-80 max-w-[calc(100vw-2rem)] rounded-lg border border-gray-200 bg-white p-4 shadow-lg dark:border-gray-700 dark:bg-gray-800">
        <div class="flex gap-3">
            <x-icon name="bell" class="mt-0.5 h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400" />
            <button type="button" x-on:click="openLive(toast.id)" class="min-w-0 flex-1 text-left">
                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100" x-text="toast?.title"></span>
                <span class="mt-0.5 block text-xs leading-relaxed whitespace-pre-line text-gray-500 dark:text-gray-400" x-text="toast?.body"></span>
            </button>
            <button type="button" x-on:click="toast = null" class="shrink-0 self-start rounded p-0.5 text-gray-400 hover:text-gray-600" aria-label="Dismiss">
                <x-icon name="x" class="h-4 w-4" />
            </button>
        </div>
    </div>
</div>
