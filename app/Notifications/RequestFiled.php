<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A request to decide, bell and email: office staff's leave or overtime to
 * every Executive; a guard's overtime to Operations (Operation HQ and the region's Satellite). (A guard's
 * leave goes through LeaveRouting → LeaveAwaitingYou instead.)
 */
final class RequestFiled extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(public readonly LeaveRequest|OvertimeRequest $request)
    {
        $request->loadMissing('user');
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
            ->subject($this->title().' ('.$this->when().')')
            ->greeting("Hello {$notifiable->name},");

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action('Review it', $this->url());
    }

    /**
     * Title: what and who. Body: one fact per line (the bell keeps the breaks).
     *
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => implode("\n", $this->lines()), 'url' => $this->url(), 'icon' => $this->request instanceof LeaveRequest ? 'clipboard' : 'clock-plus'];
    }

    private function title(): string
    {
        return ($this->request instanceof LeaveRequest ? 'Leave request' : 'Overtime request').": {$this->request->user->name}";
    }

    private function when(): string
    {
        return $this->request instanceof LeaveRequest ? $this->request->dateRangeLabel() : $this->request->date->format('M j, Y');
    }

    /**
     * "Vacation · Oct 15 – Oct 17, 2026 (3 days)" or "2 hours · Wed, Oct 14, 2026", then the reason.
     *
     * @return list<string>
     */
    private function lines(): array
    {
        if ($this->request instanceof LeaveRequest) {
            $what = "{$this->request->type->label()} · {$this->request->dateRangeLabel()} ({$this->request->calendarDaysLabel()})";
        } else {
            // "1 hour", "1.5 hours", "2 hours".
            $hours = round($this->request->requested_minutes / 60, 2);
            $unit = $this->request->requested_minutes === 60 ? 'hour' : 'hours';
            $what = "{$hours} {$unit} · {$this->request->date->format('D, M j, Y')}";
        }

        return [$what, "Reason: {$this->request->reason}"];
    }

    private function url(): string
    {
        return route($this->request instanceof LeaveRequest ? 'leave-requests' : 'overtime-requests');
    }
}
