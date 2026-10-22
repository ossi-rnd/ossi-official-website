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
 * A post will be empty unless someone is sent: bell and email to Operation
 * HQ and the region's Satellite Operation.
 */
final class AbsenceNoticeFiled extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(public readonly AbsenceNotice $notice)
    {
        $notice->loadMissing('user');
    }

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
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body())
            ->action('Assign a reliever', route('relievers'));
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => route('relievers'), 'icon' => 'arrows-right-left'];
    }

    private function title(): string
    {
        return "Reliever needed {$this->notice->duty_date->format('M j')}: {$this->notice->placeLabel()}";
    }

    private function body(): string
    {
        return "{$this->notice->user->name} can't report for duty on {$this->notice->duty_date->format('l, M j, Y')}. Reason: {$this->notice->reason}";
    }
}
