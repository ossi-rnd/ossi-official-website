<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Pushes a new bell notification to the recipient's open tabs over WebSockets
 * (Reverb), so the bell updates without polling.
 *
 * Broadcast NOW, not queued: it's sent from inside the notification's own
 * queued job, right after the database row is written.
 */
final class NotificationArrived implements ShouldBroadcastNow
{
    public function __construct(
        public readonly User $user,
        public readonly DatabaseNotification $notification,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("App.Models.User.{$this->user->id}");
    }

    public function broadcastAs(): string
    {
        return 'notification.arrived';
    }

    /**
     * Only what the bell shows: the same fields the dropdown reads from the row.
     *
     * @return array{id: string, title: string, body: string, url: string|null, icon: string, unread: int}
     */
    public function broadcastWith(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->notification->data;

        return [
            'id' => (string) $this->notification->id,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : 'Notification',
            'body' => is_string($data['body'] ?? null) ? $data['body'] : '',
            'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
            'icon' => is_string($data['icon'] ?? null) ? $data['icon'] : 'info',
            'unread' => $this->user->unreadNotifications()->count(),
        ];
    }
}
