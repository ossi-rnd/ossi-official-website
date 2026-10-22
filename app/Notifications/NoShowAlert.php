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
 * A guard on the roster didn't show up, and didn't say so. Bell and email to
 * Operation HQ and the region's Satellite Operation:
 *
 *  - detected:  the grace period ended with no clock-in, no notice, no leave;
 *  - escalated: still nobody assigned a while later (Operation HQ);
 *  - arrived:   the guard clocked in after all; nobody needs sending;
 *  - arrived_covered: the guard clocked in, but a reliever was already sent.
 */
final class NoShowAlert extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const DETECTED = 'detected';

    public const ESCALATED = 'escalated';

    public const ARRIVED = 'arrived';

    public const ARRIVED_COVERED = 'arrived_covered';

    /**
     * @param  string|null  $holdover  who is still at the post and could stay ("Pedro Reyes (Night Duty, until 6:00 AM)")
     */
    public function __construct(
        public readonly AbsenceNotice $notice,
        public readonly string $kind,
        public readonly ?string $holdover = null,
    ) {
        $notice->loadMissing(['user', 'reliever']);
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
            ->action($this->kind === self::DETECTED || $this->kind === self::ESCALATED ? 'Assign a reliever' : 'Open Relievers', route('relievers'));
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => route('relievers'), 'icon' => 'clock-alert'];
    }

    private function title(): string
    {
        $who = $this->notice->user->name;
        $place = $this->notice->placeLabel();

        return match ($this->kind) {
            self::ESCALATED => "Still uncovered: {$place} ({$who} ".($this->notice->isNoShow() ? 'no-show' : 'absent').')',
            self::ARRIVED, self::ARRIVED_COVERED => "{$who} arrived at {$place}",
            default => "No-show: {$who} · {$place}",
        };
    }

    private function body(): string
    {
        $who = $this->notice->user->name;
        $window = $this->notice->dutyWindow();
        $start = $window['start']->format('g:i A');
        $stay = $this->holdover !== null ? " {$this->holdover} is still clocked in there: ask them to stay until the reliever arrives." : '';

        return match ($this->kind) {
            self::ESCALATED => "Nobody has been sent yet. {$who} ".($this->notice->isNoShow() ? 'hasn\'t clocked in and didn\'t report' : "reported they can't come ({$this->notice->reason})")." for the {$window['shift']->name} that started {$start}.{$stay}",
            self::ARRIVED => "{$who} clocked in at {$this->notice->arrived_at?->format('g:i A')} (duty started {$start}). Nobody needs sending.",
            self::ARRIVED_COVERED => "{$who} clocked in at {$this->notice->arrived_at?->format('g:i A')}, but {$this->notice->reliever?->name} was already sent. Decide who stays.",
            default => "No clock-in and no notice for the {$window['shift']->name} that started {$start} (grace ended {$window['graceEnd']->format('g:i A')}).{$stay} Call the guard, or send a reliever.",
        };
    }
}
