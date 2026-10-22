<?php

namespace App\Notifications;

use App\Models\EmployeeProfile;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Something about you changed": a plain bell item for the many updates that need no
 * notice class of their own (a new deployment, a duty set, a new daily rate, a training
 * moved…). Bell always; email too when it matters enough ($email). Use tell() / tellProfile():
 * they skip the person who made the change and anyone who no longer works here.
 */
final class UpdateNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $icon = 'bell',
        public readonly bool $email = false,
    ) {}

    /** Tells $user, unless they made the change themselves. */
    public static function tell(?User $user, ?User $actor, string $title, string $body, string $url, string $icon = 'bell', bool $email = false): void
    {
        if ($user === null || ($actor !== null && $user->is($actor))) {
            return;
        }

        $user->loadMissing('employeeProfile');
        if ($user->employeeProfile !== null && ! $user->employeeProfile->employment_status->isActive()) {
            return;
        }

        $user->notify(new self($title, $body, $url, $icon, $email));
    }

    /** Tells the person behind an employee record, once they have an account. */
    public static function tellProfile(EmployeeProfile $profile, ?User $actor, string $title, string $body, string $url, string $icon = 'bell', bool $email = false): void
    {
        self::tell($profile->loadMissing('user')->user, $actor, $title, $body, $url, $icon, $email);
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
        return (new MailMessage)->subject($this->title)->greeting("Hello {$notifiable->name},")->line($this->body)->action('Open', $this->url);
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'icon' => $this->icon];
    }
}
