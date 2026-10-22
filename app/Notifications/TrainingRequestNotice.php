<?php

namespace App\Notifications;

use App\Enums\RequestStatus;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A training request. To Admin: a guard asked for one. To the guard: the answer,
 * and the session they were added to.
 */
final class TrainingRequestNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const FILED = 'filed';

    public const DECIDED = 'decided';

    public const ADDED_TO_SESSION = 'added_to_session';

    public function __construct(public readonly TrainingRequest $request, public readonly string $event)
    {
        $request->loadMissing(['user', 'training', 'reviewer']);
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
        return ['title' => $this->title(), 'body' => implode("\n", $this->lines()), 'url' => $this->url(), 'icon' => 'academic-cap'];
    }

    private function title(): string
    {
        $request = $this->request;

        return match (true) {
            $this->event === self::FILED => "Training request: {$request->user->name}",
            $request->training !== null => "Training: you're in {$request->training->title}",
            $request->status === RequestStatus::Approved => 'Training request approved',
            default => 'Training request declined',
        };
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $request = $this->request;
        $training = $request->training;

        if ($this->event === self::FILED) {
            return [
                $request->kind->label().($request->preferred_from ? ' · from '.$request->preferred_from->format('M j, Y') : ''),
                $request->reason,
            ];
        }

        $lines = [match (true) {
            $training !== null => "{$training->dateLabel()}".($training->venue ? " at {$training->venue}" : '').'.',
            $request->status === RequestStatus::Approved => "Your request for {$request->kind->label()} was approved. You'll be told when you're added to a session.",
            default => "Your request for {$request->kind->label()} was not approved.",
        }];

        if (filled($request->review_note)) {
            $lines[] = 'Note: '.$request->review_note;
        }

        return $lines;
    }

    private function url(): string
    {
        return $this->event === self::FILED ? route('trainings') : route('my-training');
    }
}
