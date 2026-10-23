<?php

namespace App\Notifications;

use App\Models\LeaveEndorsement;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A leave request is waiting for you: to endorse (Satellite Operation,
 * Operation HQ) or to decide (HR for guards; an office staffer's head, App\Support\Heads).
 * Includes the endorsements so far.
 */
final class LeaveAwaitingYou extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(public readonly LeaveRequest $leave)
    {
        $leave->loadMissing(['user', 'endorsements.endorser']);
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
        $mail = (new MailMessage)
            ->subject("{$this->title()} ({$this->leave->dateRangeLabel()})")
            ->greeting("Hello {$notifiable->name},");

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action($this->asksToEndorse() ? 'Endorse it' : 'Review it', route('leave-requests'));
    }

    /**
     * Title: what and who. Body: one fact per line (the bell keeps the breaks).
     *
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => implode("\n", $this->lines()), 'url' => route('leave-requests'), 'icon' => 'clipboard'];
    }

    private function asksToEndorse(): bool
    {
        return $this->leave->stage?->endorses() ?? false;
    }

    private function title(): string
    {
        return ($this->asksToEndorse() ? 'Leave to endorse' : 'Leave to decide').": {$this->leave->user->name}";
    }

    /**
     * "Vacation · Oct 15 – Oct 17, 2026 (3 days)", "Reason: going to Japan", then each endorsement so far.
     *
     * @return list<string>
     */
    private function lines(): array
    {
        // Confidential (VAWC): an email may be read over a shoulder, so the type and reason stay in the app.
        if ($this->leave->type->isConfidential()) {
            return ["Confidential leave · {$this->leave->dateRangeLabel()} ({$this->leave->calendarDaysLabel()})", 'Open it in the app to read it.'];
        }

        return [
            "{$this->leave->type->label()} · {$this->leave->dateRangeLabel()} ({$this->leave->calendarDaysLabel()})",
            "Reason: {$this->leave->reason}",
            ...$this->leave->endorsements->map(fn (LeaveEndorsement $endorsement) => $this->endorsementLine($endorsement))->values()->all(),
        ];
    }

    private function endorsementLine(LeaveEndorsement $endorsement): string
    {
        return "{$endorsement->stage->label()} ({$endorsement->endorser->name}): ".($endorsement->recommends ? 'endorsed' : 'not recommended').($endorsement->note ? " — {$endorsement->note}" : '').'.';
    }
}
