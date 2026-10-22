<?php

namespace App\Notifications;

use App\Models\EmployeeProfile;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "It's Juan's birthday today." Bell only, to HR and (for a guard) Operations. Never the age.
 */
final class BirthdayToday extends Notification implements ShouldQueue
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
        $where = $this->profile->is_office_staff ? 'Office staff' : ($this->profile->detachment ?? 'Not deployed yet');

        return [
            'title' => "Birthday today: {$this->profile->name}",
            'body' => "{$where}. A greeting goes a long way.",
            'url' => route('dashboard').'#birthdays',
            'icon' => 'gift',
        ];
    }
}
