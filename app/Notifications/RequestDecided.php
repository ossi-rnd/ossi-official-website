<?php

namespace App\Notifications;

use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The answer to someone's own leave or overtime request: bell and email, with
 * the reviewer's note. Approved overtime says how much, when less was approved.
 */
final class RequestDecided extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(public readonly LeaveRequest|OvertimeRequest $request)
    {
        $request->loadMissing('reviewer');
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
            ->action('Open', $this->url());
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => $this->url(), 'icon' => $this->request instanceof LeaveRequest ? 'clipboard' : 'clock-plus'];
    }

    private function approved(): bool
    {
        return $this->request->status === RequestStatus::Approved;
    }

    private function title(): string
    {
        $verdict = $this->approved() ? 'approved' : 'not approved';

        return match (true) {
            $this->request instanceof LeaveRequest => "Leave {$verdict}: {$this->request->dateRangeLabel()}",
            $this->request->addedForThem() => "Extra time added: {$this->request->date->format('M j')}",
            default => "Overtime {$verdict}: {$this->request->date->format('M j')}",
        };
    }

    private function body(): string
    {
        $by = $this->request->reviewer->name ?? 'Your supervisor';
        $note = filled($this->request->review_note) ? " Note: {$this->request->review_note}" : '';

        if ($this->request instanceof OvertimeRequest && $this->approved()) {
            $approved = (int) $this->request->approved_minutes;
            $hours = fn (int $minutes): string => rtrim(rtrim(number_format($minutes / 60, 2), '0'), '.').' '.($minutes === 60 ? 'hour' : 'hours');
            $less = $approved < $this->request->requested_minutes ? " (you asked for {$hours($this->request->requested_minutes)})" : '';

            // Operations added it: nothing was asked, so say what and why.
            if ($this->request->addedForThem()) {
                return "{$by} added {$hours($approved)} of extra time for you: ".rtrim($this->request->reason, '. ').'. Paid up to the time you clocked.';
            }

            return "{$by} approved {$hours($approved)}{$less}.{$note}";
        }

        return "{$by} ".($this->approved() ? 'approved' : 'did not approve')." it.{$note}";
    }

    private function url(): string
    {
        return $this->request instanceof LeaveRequest ? route('leave') : route('overtime');
    }
}
