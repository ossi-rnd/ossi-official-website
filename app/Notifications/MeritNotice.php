<?php

namespace App\Notifications;

use App\Models\MeritRecord;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Merits and demerits. Given or taken back: to the employee (for a demerit,
 * with the date they can reply by). Replied: to whoever gave the demerit.
 */
final class MeritNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const GIVEN = 'given';

    public const REPLIED = 'replied';

    public const VOIDED = 'voided';

    public function __construct(
        public readonly MeritRecord $record,
        public readonly string $event,
    ) {
        $record->loadMissing('user.employeeProfile');
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
        $mail = (new MailMessage)->subject($this->title())->greeting("Hello {$notifiable->name},");

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action('Open', $this->url());
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => implode(' ', $this->lines()), 'url' => $this->url(), 'icon' => 'flag'];
    }

    private function title(): string
    {
        $record = $this->record;
        $points = sprintf('%+d', $record->points);

        return match ($this->event) {
            self::GIVEN => ($record->isDemerit() ? 'Demerit: ' : 'Merit: ')."{$record->title} ({$points})",
            self::REPLIED => "{$record->user->name} replied to a demerit",
            default => ($record->isDemerit() ? 'Demerit' : 'Merit')." taken back: {$record->title}",
        };
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $record = $this->record;

        return match ($this->event) {
            self::GIVEN => $record->isDemerit() && $record->reply_until !== null
                ? ["{$record->occurred_on->format('M j, Y')}: {$record->reason}", "You can add your side on My Profile until {$record->reply_until->format('M j')}."]
                : ["{$record->occurred_on->format('M j, Y')}: {$record->reason}"],
            self::REPLIED => ["{$record->title}: \"{$record->employee_reply}\""],
            default => ["It no longer counts. Reason: {$record->void_reason}"],
        };
    }

    private function url(): string
    {
        return $this->event === self::REPLIED
            ? route('employees.show', $this->record->user->employeeProfile ?? 0)
            : route('profile');
    }
}
