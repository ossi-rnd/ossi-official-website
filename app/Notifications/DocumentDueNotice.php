<?php

namespace App\Notifications;

use App\Models\CompanyDocument;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A license or document is due for renewal (or overdue). Bell always; email
 * too from 30 days out.
 */
final class DocumentDueNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(
        public readonly CompanyDocument $document,
        public readonly int $daysLeft,
        public readonly bool $email = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $this->email ? ['database', 'mail'] : ['database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body())
            ->action('Open Documents', $this->url());
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => $this->url(), 'icon' => 'folder'];
    }

    private function title(): string
    {
        return match (true) {
            $this->daysLeft < 0 => "Overdue for renewal: {$this->document->title}",
            $this->daysLeft === 0 => "Due today: {$this->document->title}",
            default => "Due in {$this->daysLeft} ".str('day')->plural($this->daysLeft).": {$this->document->title}",
        };
    }

    private function body(): string
    {
        $date = $this->document->expires_on?->format('M j, Y');

        return $this->daysLeft < 0
            ? "It was due on {$date}. Upload the renewed copy on Documents → Renew."
            : "It's due on {$date}. Start the renewal, then upload the new copy on Documents → Renew.";
    }

    private function url(): string
    {
        return route('documents', ['tab' => $this->document->category->value]);
    }
}
