<?php

namespace App\Notifications;

use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A deduction that needs the employee's written authorization (Art. 113).
 * Requested: to the employee. Authorized or declined: to Accounting.
 */
final class DeductionAuthorizationNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const REQUESTED = 'requested';

    public const AUTHORIZED = 'authorized';

    public const DECLINED = 'declined';

    public function __construct(
        public readonly EmployeeDeduction $deduction,
        public readonly string $event,
    ) {
        $deduction->loadMissing('user');
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

        return $mail->action($this->event === self::REQUESTED ? 'Review it' : 'Open', $this->url());
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => implode(' ', $this->lines()), 'url' => $this->url(), 'icon' => 'banknotes'];
    }

    private function title(): string
    {
        $d = $this->deduction;

        return match ($this->event) {
            self::REQUESTED => "Please review a deduction: {$d->label}",
            self::AUTHORIZED => "{$d->user->name} authorized a deduction: {$d->label}",
            default => "{$d->user->name} doesn't agree to a deduction: {$d->label}",
        };
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $d = $this->deduction;
        $terms = $d->type->label().': ₱'.number_format((float) $d->installment_amount, 2).' per payslip'
            .($d->original_amount !== null ? ', ₱'.number_format((float) $d->original_amount, 2).' in all' : '').'.';

        return match ($this->event) {
            self::REQUESTED => [$terms, 'Nothing is deducted until you authorize it on My Payslips. If something is wrong, tell Accounting there.'],
            self::AUTHORIZED => [$terms, 'Payslips take it from now on.'],
            default => [$terms, 'Nothing is deducted. Talk to them, then delete it, or add it again as signed on paper.'],
        };
    }

    private function url(): string
    {
        return $this->event === self::REQUESTED ? route('payslips') : route('payroll');
    }
}
