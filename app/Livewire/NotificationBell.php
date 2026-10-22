<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithUser;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The bell in the top bar. Deliberately NO wire:poll: a poll is a request,
 * and every request resets the idle timeout, so polling would keep an
 * unattended screen signed in forever. New notifications are pushed over
 * the WebSocket instead (NotificationArrived, Reverb) and drawn in the browser
 * by live-bell.js; without Reverb the bell still refreshes on each page load.
 *
 * @property-read int $unreadCount
 * @property-read Collection<int, DatabaseNotification> $recent
 */
class NotificationBell extends Component
{
    use InteractsWithUser;

    #[Computed]
    public function unreadCount(): int
    {
        return $this->currentUser()->unreadNotifications()->count();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function recent(): Collection
    {
        return $this->currentUser()->notifications()->latest()->limit(8)->get();
    }

    /**
     * Marks one as read and follows its link. Only the link's path is kept and
     * opened on the address the person is using now: a notice queued by the
     * worker carries APP_URL's host, which differs from a tunnel or a renamed
     * domain. Dropping the host also means a link can never lead off-site.
     */
    public function open(string $id): void
    {
        /** @var DatabaseNotification $notification */
        $notification = $this->currentUser()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        $path = $this->localPath($notification->data['url'] ?? null);

        if ($path !== null) {
            $this->redirect(url($path), navigate: true);

            return;
        }

        unset($this->unreadCount, $this->recent);
    }

    /**
     * "https://any-host/leave-requests?x=1" → "/leave-requests?x=1"; null for anything that isn't a link.
     */
    private function localPath(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $path = '/'.ltrim($parts['path'] ?? '', '/');

        return isset($parts['query']) ? "{$path}?{$parts['query']}" : $path;
    }

    public function markAllRead(): void
    {
        $this->currentUser()->unreadNotifications()->update(['read_at' => now()]);

        unset($this->unreadCount, $this->recent);
    }

    public function render(): View
    {
        // Icons the notification classes use, so one that arrives live is drawn with its own.
        return view('livewire.notification-bell', [
            'liveIcons' => ['shield', 'clipboard', 'clock-plus', 'arrows-right-left', 'user', 'banknotes', 'megaphone', 'folder', 'flag', 'phone', 'info'],
        ]);
    }
}
