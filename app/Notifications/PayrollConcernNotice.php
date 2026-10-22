<?php

namespace App\Notifications;

use App\Enums\PayrollConcernStatus;
use App\Models\PayrollConcern;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Report a problem" on a payslip. Filed: to Accounting at once (the bell is
 * written in the request). Answered: to the employee, with Accounting's reply
 * and, when it's paid, which payslip. Bell and email both ways: it's pay.
 */
final class PayrollConcernNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const FILED = 'filed';

    public const ANSWERED = 'answered';

    public function __construct(
        public readonly PayrollConcern $concern,
        public readonly string $event,
    ) {
        $concern->loadMissing(['user', 'run', 'adjustment.run']);
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
        return ['title' => $this->title(), 'body' => implode(' ', $this->lines()), 'url' => $this->url(), 'icon' => 'banknotes'];
    }

    private function title(): string
    {
        return $this->event === self::FILED
            ? "Payroll concern: {$this->concern->user->name}"
            : 'Your payroll concern: '.$this->concern->status->label();
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $concern = $this->concern;
        $period = $concern->run->periodLabel();

        if ($this->event === self::FILED) {
            return ["{$concern->category->label()} ({$period}).", $concern->details];
        }

        $lines = ["About your {$period} payslip: {$concern->response}"];

        if ($concern->status === PayrollConcernStatus::Resolved && $concern->adjustment !== null) {
            $amount = (float) $concern->adjustment->amount;
            $lines[] = ($amount >= 0 ? 'Added ₱' : 'Deducted ₱').number_format(abs($amount), 2)." on your {$concern->adjustment->run->periodLabel()} payslip.";
        }

        return $lines;
    }

    private function url(): string
    {
        return $this->event === self::FILED
            ? route('payroll-concerns', ['open' => $this->concern->id])
            : route('payslips', ['run' => $this->concern->payroll_run_id]);
    }
}
