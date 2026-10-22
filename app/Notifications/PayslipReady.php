<?php

namespace App\Notifications;

use App\Models\PayrollRun;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * To everyone with a payslip in the run: when it's approved ("ready to view")
 * and when it's marked paid. Bell only: a whole payroll would otherwise send
 * hundreds of emails at once.
 */
final class PayslipReady extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(
        public readonly PayrollRun $run,
        public readonly bool $paid,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        $period = $this->run->periodLabel();

        return $this->paid
            ? ['title' => "Pay released: {$period}", 'body' => 'Your pay for this period has been released. Your payslip shows the details.', 'url' => route('payslips'), 'icon' => 'banknotes']
            // The payslip's own disclaimer, so it's read before the figures (config payroll.disclaimer).
            : ['title' => "Payslip ready: {$period}", 'body' => 'Your payslip for this period is approved. Open it to check your days, deductions and net pay. '.config('payroll.disclaimer'), 'url' => route('payslips'), 'icon' => 'banknotes'];
    }
}
