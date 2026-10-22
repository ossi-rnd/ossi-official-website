<?php

namespace App\Notifications;

use App\Models\Radio;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A radio's NTC permit is due for renewal (or expired). Bell always; email too from 30 days out.
 */
final class RadioPermitDue extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(
        public readonly Radio $radio,
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
            ->action('Open Radios', route('radios'));
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => route('radios'), 'icon' => 'radio'];
    }

    private function title(): string
    {
        $radio = "radio SN {$this->radio->serial_number}";

        return match (true) {
            $this->daysLeft < 0 => "NTC permit expired: {$radio}",
            $this->daysLeft === 0 => "NTC permit expires today: {$radio}",
            default => "NTC permit expires in {$this->daysLeft} ".str('day')->plural($this->daysLeft).": {$radio}",
        };
    }

    private function body(): string
    {
        $date = $this->radio->permit_expires_on?->format('M j, Y');
        $permit = $this->radio->ntc_permit_number !== null ? " (permit {$this->radio->ntc_permit_number})" : '';

        return $this->daysLeft < 0
            ? "{$this->radio->brand} {$this->radio->model}{$permit} expired on {$date}. Renew it with the NTC, then enter the new date on Radios."
            : "{$this->radio->brand} {$this->radio->model}{$permit} expires on {$date}. Start the NTC renewal, then enter the new date on Radios.";
    }
}
