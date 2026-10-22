<?php

namespace App\Notifications;

use App\Models\Resignation;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every step of a resignation. Filed and withdrawn go to its handler (HR, or
 * the President or Vice President for HR staff); accepted and completed to the employee; a
 * guard's acceptance also to Operations, so the post is covered from the
 * last day. All by bell and email: these are dates people plan around.
 */
final class ResignationNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const FILED = 'filed';

    public const WITHDRAWN = 'withdrawn';

    public const ACCEPTED = 'accepted';

    public const ACCEPTED_OPERATIONS = 'accepted_operations';

    public const COMPLETED = 'completed';

    public function __construct(
        public readonly Resignation $resignation,
        public readonly string $event,
    ) {
        $resignation->loadMissing('user.employeeProfile');
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
        return ['title' => $this->title(), 'body' => implode("\n", $this->lines()), 'url' => $this->url(), 'icon' => 'logout'];
    }

    private function title(): string
    {
        $name = $this->resignation->user->name;

        return match ($this->event) {
            self::FILED => "Resignation to accept: {$name}",
            self::WITHDRAWN => "Resignation withdrawn: {$name}",
            self::ACCEPTED => 'Your resignation was accepted',
            self::ACCEPTED_OPERATIONS => "Guard leaving: {$name}",
            default => 'Your clearance is complete',
        };
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $resignation = $this->resignation;
        $lastDay = $resignation->effectiveLastDay()->format('M j, Y');

        return match ($this->event) {
            // Optional lines come last, so dropping them never leaves a gap in the list.
            self::FILED => array_filter([
                "{$resignation->reasonLabel()} · last day asked: {$lastDay} ({$resignation->noticeDays()} days' notice)",
                $resignation->asks_waiver ? 'Asks to waive the 30-day notice.' : null,
            ]),
            self::WITHDRAWN => ["They took back the resignation they filed on {$resignation->created_at->format('M j')}."],
            self::ACCEPTED => array_filter([
                "Last day: {$lastDay}.",
                'Final pay is due by '.$resignation->finalPayDue()->format('M j, Y').', after clearance.',
                filled($resignation->decision_note) ? "Note: {$resignation->decision_note}" : null,
            ]),
            self::ACCEPTED_OPERATIONS => [
                ($resignation->user->employeeProfile?->assignmentLabel() ?? 'No post on record')." · last day {$lastDay}",
                'Plan a replacement for the post from the day after.',
            ],
            default => [
                'Everything on your clearance is done.',
                'Your final pay and Certificate of Employment have been released. Thank you for your service.',
            ],
        };
    }

    private function url(): string
    {
        return match ($this->event) {
            self::FILED, self::WITHDRAWN => route('resignations', ['open' => $this->resignation->id]),
            self::ACCEPTED_OPERATIONS => route('relievers'),
            default => route('resignation'),
        };
    }
}
