<?php

namespace App\Notifications;

use App\Models\EmployeeRequirement;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A 201 document needs someone's attention: it expires soon, it expired, HR
 * returned it, or a promised submission date passed.
 *
 * The employee is sent to My Profile and staff to the employee record, so
 * the same notice works for both audiences.
 */
final class RequirementNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    public const RETURNED = 'returned';

    public const PROMISE_OVERDUE = 'promise_overdue';

    public function __construct(
        public readonly EmployeeRequirement $record,
        public readonly string $reason,
        public readonly bool $email = false,
    ) {
        // Loaded now so the queued copy carries it: the worker re-fetches the
        // model with the relations it had, and strict mode forbids lazy loads.
        $record->loadMissing('profile');
    }

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
            ->subject($this->title($notifiable))
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body($notifiable))
            ->action('Open the 201 file', $this->url($notifiable));
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title($notifiable), 'body' => $this->body($notifiable), 'url' => $this->url($notifiable), 'icon' => 'clipboard'];
    }

    private function isOwner(User $notifiable): bool
    {
        return $this->record->profile->user_id === $notifiable->id;
    }

    private function title(User $notifiable): string
    {
        $document = $this->record->requirement->label();
        $whose = $this->isOwner($notifiable) ? 'Your' : $this->record->profile->name.'\'s';

        return match ($this->reason) {
            self::EXPIRED => "{$whose} {$document} has expired",
            self::RETURNED => "{$whose} {$document} was returned by HR",
            self::PROMISE_OVERDUE => "{$whose} {$document} is past its promised date",
            default => "{$whose} {$document} expires soon",
        };
    }

    private function body(User $notifiable): string
    {
        $record = $this->record;

        return match ($this->reason) {
            self::EXPIRED => "It expired on {$record->expires_on?->format('M j, Y')}. Submit a renewed copy to HR.",
            self::RETURNED => 'Reason: '.($record->note ?? 'see HR').'. Please upload a new copy.',
            self::PROMISE_OVERDUE => "It was promised by {$record->promised_on?->format('M j, Y')} and is still missing.",
            default => "It expires on {$record->expires_on?->format('M j, Y')}. Renew it before then.",
        };
    }

    private function url(User $notifiable): string
    {
        return $this->isOwner($notifiable) ? route('profile') : route('employees.show', $this->record->profile);
    }
}
