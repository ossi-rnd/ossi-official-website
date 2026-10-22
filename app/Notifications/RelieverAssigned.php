<?php

namespace App\Notifications;

use App\Models\AbsenceNotice;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Two audiences: the reliever (bell and email: they have somewhere to be) and
 * the absent guard (bell: their post is covered).
 */
final class RelieverAssigned extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(public readonly AbsenceNotice $notice, public readonly bool $forReliever)
    {
        $notice->loadMissing(['user', 'reliever']);
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $this->forReliever ? ['database', 'mail'] : ['database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body())
            ->action('Open My Attendance', route('attendance'));
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => route($this->forReliever ? 'attendance' : 'absence-notice'), 'icon' => 'arrows-right-left'];
    }

    private function title(): string
    {
        $date = $this->notice->duty_date->format('M j');

        return $this->forReliever
            ? "Relief duty {$date}: {$this->notice->placeLabel()}"
            : "Your post is covered on {$date}";
    }

    private function body(): string
    {
        $date = $this->notice->duty_date->format('l, M j, Y');
        $note = $this->notice->cover_note !== null ? " Note: {$this->notice->cover_note}" : '';

        return $this->forReliever
            ? "You're relieving {$this->notice->user->name} at {$this->notice->placeLabel()} on {$date}.{$note}"
            : ($this->notice->reliever->name ?? 'A reliever')." will cover {$this->notice->placeLabel()} on {$date}.";
    }
}
