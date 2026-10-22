<div class="mx-auto max-w-3xl space-y-6">
    <x-ui.card title="Announcements" description="Advisories from the office. Live ones also show as a banner on your dashboard.">
        @can(\App\Enums\Ability::PublishContent->value)
            <x-slot:actions>
                <x-ui.button :href="route('announcements')" variant="secondary" size="sm" wire:navigate>Manage</x-ui.button>
            </x-slot:actions>
        @endcan

        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($this->announcements as $announcement)
                @php($picked = $announcement->id === $id)
                {{-- The one opened from the bell is tinted and scrolled into view. --}}
                <article wire:key="announcement-{{ $announcement->id }}" id="announcement-{{ $announcement->id }}"
                    @if ($picked) x-data x-init="$el.scrollIntoView({ block: 'center' })" @endif
                    class="flex gap-3 px-5 py-4 {{ $picked ? 'bg-blue-50/60 dark:bg-blue-500/5' : '' }}">
                    <x-icon name="megaphone" class="mt-0.5 h-5 w-5 shrink-0 {{ $announcement->is_urgent ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $announcement->title }}</h2>
                            @if ($announcement->is_urgent)<x-ui.badge tone="red">Urgent</x-ui.badge>@endif
                            @unless ($announcement->isLive())<x-ui.badge>Ended</x-ui.badge>@endunless
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $announcement->starts_at->format('M j, Y · g:i A') }}</p>
                        {{-- e() + nl2br: line breaks kept, nothing typed is rendered as HTML. --}}
                        <div class="mt-2 text-sm leading-relaxed text-gray-700 dark:text-gray-300">{!! nl2br(e($announcement->body)) !!}</div>
                    </div>
                </article>
            @empty
                <p class="px-5 py-8 text-center text-sm text-gray-500">No announcements in the last {{ \App\Livewire\AnnouncementBoard::DAYS }} days.</p>
            @endforelse
        </div>
    </x-ui.card>
</div>
