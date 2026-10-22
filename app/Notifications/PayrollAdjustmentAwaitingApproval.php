<?php

namespace App\Notifications;

use App\Models\PayrollAdjustment;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A large payroll adjustment needs a second person before it reaches a payslip.
 */
final class PayrollAdjustmentAwaitingApproval extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(public readonly PayrollAdjustment $adjustment)
    {
        $adjustment->loadMissing(['employee', 'creator', 'payrollRun']);
    }

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
        $adjustment = $this->adjustment;
        $amount = '₱'.number_format(abs((float) $adjustment->amount), 2);

        return [
            'title' => "Adjustment to approve: {$adjustment->employee->name}",
            'body' => "{$adjustment->creator->name} added ".($adjustment->isDeduction() ? "a {$amount} deduction" : "{$amount}")." ({$adjustment->category->label()}) for {$adjustment->payrollRun->periodLabel()}: {$adjustment->reason}",
            'url' => route('payroll', ['run' => $adjustment->payroll_run_id]),
            'icon' => 'banknotes',
        ];
    }
}
