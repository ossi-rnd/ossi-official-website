<?php

namespace App\Notifications;

use App\Models\EmployeeRequirement;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An employee uploaded a 201 document: HR has something to check.
 * Bell only; uploads are frequent and none of them is urgent.
 */
final class RequirementSubmitted extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(public readonly EmployeeRequirement $record)
    {
        $record->loadMissing('profile');
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
        $profile = $this->record->profile;

        return [
            'title' => "201 document to check: {$profile->name}",
            'body' => "{$profile->name} ({$profile->employee_number}) uploaded {$this->record->requirement->label()}.",
            'url' => route('employees.show', $profile),
            'icon' => 'clipboard',
        ];
    }
}
