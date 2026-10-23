<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A missed patrol round, or someone away too long or silent: bell and email,
 * to Operations for guards and to an office staffer's head (App\Support\Heads).
 */
final class PatrolAlert extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(
        public readonly string $guardName,
        public readonly string $headline,
        public readonly string $detail,
        public readonly ?string $url = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->guardName}: {$this->headline}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->guardName}: {$this->headline}.")
            ->line($this->detail)
            ->action('Open', $this->url ?? route('patrols'));
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => "{$this->guardName}: {$this->headline}", 'body' => $this->detail, 'url' => $this->url ?? route('patrols'), 'icon' => 'shield'];
    }
}
