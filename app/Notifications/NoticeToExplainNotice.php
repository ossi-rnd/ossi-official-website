<?php

namespace App\Notifications;

use App\Enums\NteOutcome;
use App\Models\NoticeToExplain;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every step of a Notice to Explain, by bell and email (these are notices the
 * employee must actually receive). Issued, hearing and decided: to the employee.
 * Explained: to whoever issued it. A dismissal: to HR too, to update the record.
 */
final class NoticeToExplainNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const ISSUED = 'issued';

    public const EXPLAINED = 'explained';

    public const HEARING = 'hearing';

    public const DECIDED = 'decided';

    public const DISMISSAL_HR = 'dismissal_hr';

    public function __construct(
        public readonly NoticeToExplain $notice,
        public readonly string $event,
    ) {
        $notice->loadMissing('user.employeeProfile');
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
        return ['title' => $this->title(), 'body' => implode(' ', $this->lines()), 'url' => $this->url(), 'icon' => 'scroll'];
    }

    private function title(): string
    {
        $notice = $this->notice;

        return match ($this->event) {
            self::ISSUED => "Notice to Explain: {$notice->subject}",
            self::EXPLAINED => "{$notice->user->name} explained: {$notice->subject}",
            self::HEARING => "Hearing set: {$notice->subject}",
            self::DISMISSAL_HR => "Dismissal decided: {$notice->user->name}",
            default => "Decision on your Notice to Explain: {$notice->subject}",
        };
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $notice = $this->notice;

        return match ($this->event) {
            self::ISSUED => ["Please explain in writing by {$notice->explain_by->format('M j, Y')}. It could lead to: {$notice->possible_penalty->label()}.", 'Open My Notices to read it and give your explanation.'],
            self::EXPLAINED => [$notice->wants_hearing ? 'They ask for a hearing.' : 'Read it and decide.'],
            self::HEARING => ["{$notice->hearing_at?->format('D, M j, g:i A')} at {$notice->hearing_place}."],
            self::DISMISSAL_HR => ["{$notice->subject}. Set \"Terminated\" on their employee record, and start the clearance and final pay."],
            default => [
                $notice->decision?->label().($notice->decision === NteOutcome::Suspension ? ": {$notice->suspension_days} days from {$notice->suspension_from?->format('M j')}" : '').'.',
                (string) $notice->decision_reason,
            ],
        };
    }

    private function url(): string
    {
        return in_array($this->event, [self::EXPLAINED, self::DISMISSAL_HR], true)
            ? route('notices-to-explain', ['open' => $this->notice->id])
            : route('my-notices');
    }
}
