<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * An announcement went live. Always in the bell; urgent ones are emailed too,
 * so a routine advisory doesn't fill every inbox.
 */
final class AnnouncementPublished extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(public readonly Announcement $announcement) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $this->announcement->is_urgent ? ['database', 'mail'] : ['database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Urgent advisory: '.$this->announcement->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->announcement->body)
            ->action('Open '.config('app.name'), route('home'));
    }

    /**
     * Opens Announcements with this one highlighted, not the dashboard: the
     * banner there is often the page already open, and goes once dismissed.
     *
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return [
            'title' => ($this->announcement->is_urgent ? 'Urgent: ' : '').$this->announcement->title,
            'body' => Str::limit($this->announcement->body, 140),
            'url' => route('news', ['id' => $this->announcement->id]),
            'icon' => 'megaphone',
        ];
    }
}
