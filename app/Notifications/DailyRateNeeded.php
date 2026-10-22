<?php

namespace App\Notifications;

use App\Models\EmployeeProfile;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * HR added an employee and no company rate exists for their position:
 * Accounting sets their daily rate. Until then payroll can't pay them. Bell only.
 */
final class DailyRateNeeded extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(public readonly EmployeeProfile $profile) {}

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
        return [
            'title' => "Daily rate needed: {$this->profile->name}",
            // One fact per line: the number on its own, so it never breaks at its dash.
            'body' => "Employee no. {$this->profile->employee_number}, just added by HR.\nSet their daily rate before their first payroll.",
            'url' => route('daily-rates', ['q' => $this->profile->employee_number]),
            'icon' => 'banknotes',
        ];
    }
}
