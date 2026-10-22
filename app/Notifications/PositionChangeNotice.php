<?php

namespace App\Notifications;

use App\Models\PositionChange;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A promotion or change of position. To the employee: congratulations (or the
 * new position) and from when. To Accounting: the rate it brings, or that their
 * personal rate still wins and should be reviewed on Daily Rates.
 */
final class PositionChangeNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const EMPLOYEE = 'employee';

    public const ACCOUNTING = 'accounting';

    public function __construct(
        public readonly PositionChange $change,
        public readonly string $audience,
        public readonly ?float $newRate = null,
        public readonly bool $hasPersonalRate = false,
    ) {
        $change->loadMissing('profile');
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
        return ['title' => $this->title(), 'body' => implode(' ', $this->lines()), 'url' => $this->url(), 'icon' => 'user'];
    }

    private function title(): string
    {
        $change = $this->change;
        $to = $change->to_position->label();

        if ($this->audience === self::EMPLOYEE) {
            return $change->kind === PositionChange::PROMOTION ? "Congratulations: promoted to {$to}" : "Your position: {$to}";
        }

        return "{$change->kindLabel()}: {$change->profile->name} → {$to}";
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $change = $this->change;
        $from = $change->effective_on->format('M j, Y');

        if ($this->audience === self::EMPLOYEE) {
            return ["From {$from} (your {$change->effective_on->format('M j')} payslip onward).", (string) $change->reason];
        }

        return [
            "From {$from}: ".($change->from_position?->label() ?? 'no position')." → {$change->to_position->label()}.",
            match (true) {
                $this->hasPersonalRate => 'They have a personal rate, which still applies over the position\'s. Review it on Daily Rates.',
                $this->newRate !== null => 'The position\'s rate, ₱'.number_format($this->newRate, 2).', applies from that payslip.',
                default => 'That position has no company rate yet. Set it on Daily Rates.',
            },
        ];
    }

    private function url(): string
    {
        return $this->audience === self::EMPLOYEE ? route('profile') : route('daily-rates');
    }
}
