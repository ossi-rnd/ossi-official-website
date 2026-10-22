@props(['user'])

{{--
    Live announcements for this person, above every page. The audience is
    filtered in PHP rather than SQL: few announcements are live at once, and
    JSON queries differ between SQLite and MySQL.

    Dismissing is per browser (localStorage): a convenience, not a record.
    Nothing depends on it, so a private window simply shows the banner again.
--}}
@php
    /** @var \App\Models\User $user */
    $live = \App\Models\Announcement::query()->live()->latest('starts_at')->limit(10)->get()
        ->filter(fn (\App\Models\Announcement $announcement) => $announcement->isFor($user))
        ->take(3);
@endphp

@if ($live->isNotEmpty())
    <div class="mb-6 space-y-3">
        @foreach ($live as $announcement)
            <div wire:key="announcement-banner-{{ $announcement->id }}"
                x-data="{
                    key: 'ossi-dismissed-announcement-{{ $announcement->id }}',
                    hidden: false,
                    init() { try { this.hidden = localStorage.getItem(this.key) === '1' } catch (e) {} },
                    dismiss() { this.hidden = true; try { localStorage.setItem(this.key, '1') } catch (e) {} },
                }"
                x-show="! hidden" x-cloak
                class="flex items-start gap-3 rounded-lg border px-4 py-3 text-sm {{ $announcement->is_urgent ? 'border-red-200 bg-red-50 text-red-900 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-100' : 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-100' }}"
                role="{{ $announcement->is_urgent ? 'alert' : 'status' }}">
                <x-icon name="megaphone" class="mt-0.5 h-5 w-5 shrink-0" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ $announcement->is_urgent ? 'Urgent: ' : '' }}{{ $announcement->title }}</p>
                    <p class="mt-0.5 whitespace-pre-line opacity-90">{{ $announcement->body }}</p>
                </div>
                <button type="button" aria-label="Dismiss announcement" class="shrink-0 rounded-md p-1 opacity-60 hover:opacity-100"
                    x-on:click="dismiss()">
                    <x-icon name="x" class="h-4 w-4" />
                </button>
            </div>
        @endforeach
    </div>
@endif
