<?php

namespace App\Notifications;

use App\Models\FinalPay;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A final-pay statement moving along: to sign (each signer when it's their
 * turn; the President or Vice President once Accounting has), sent back (to Accounting),
 * released (to the employee, by email too, and to Accounting).
 */
final class FinalPayNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const TO_SIGN = 'to_sign';

    public const RETURNED = 'returned';

    public const RELEASED = 'released';

    public const RELEASED_TO_EMPLOYEE = 'released_to_employee';

    public function __construct(
        public readonly FinalPay $finalPay,
        public readonly string $event,
    ) {
        $finalPay->loadMissing('user');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $this->event === self::RELEASED_TO_EMPLOYEE ? ['database', 'mail'] : ['database'];
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
        return ['title' => $this->title(), 'body' => implode("\n", $this->lines()), 'url' => $this->url(), 'icon' => 'banknotes'];
    }

    private function title(): string
    {
        $name = $this->finalPay->user->name;

        return match ($this->event) {
            self::TO_SIGN => "Final pay to sign: {$name}",
            self::RETURNED => "Final pay sent back: {$name}",
            self::RELEASED => "Final pay released: {$name}",
            default => 'Your final pay has been released',
        };
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $pay = $this->finalPay;
        $net = '₱'.number_format((float) $pay->net, 2);

        return match ($this->event) {
            self::TO_SIGN => ["Net {$net}. ".count($pay->pendingSigners()).' signature(s) to go.'],
            self::RETURNED => ['Note: '.($pay->return_note ?? '—')],
            self::RELEASED => ["Net {$net}, signed by all five."],
            // The optional line sits in the middle, so re-index after dropping it.
            default => array_values(array_filter([
                "Net {$net}: your pro-rated 13th month and cash bond refund, less what you still owed (if you agreed to it).",
                (float) $pay->receivable > 0 ? '₱'.number_format((float) $pay->receivable, 2).' still owed after this; HR will contact you.' : null,
                'Your salary up to your last day is paid in the regular payroll.',
            ])),
        };
    }

    private function url(): string
    {
        return $this->event === self::RELEASED_TO_EMPLOYEE ? route('resignation') : route('final-pay', ['pay' => $this->finalPay->id]);
    }
}
