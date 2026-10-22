<?php

namespace App\Notifications;

use App\Models\TimeLogPunch;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A guard clocked in or out on a phone that isn't their company phone. The punch
 * counts; this asks Operations and HR to check it was really them. Bell only.
 */
final class OtherPhoneUsed extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public function __construct(public readonly TimeLogPunch $punch)
    {
        $punch->loadMissing('timeLog.user.device');
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
        $guard = $this->punch->timeLog->user;
        $phone = $this->punch->device_label ?? 'an unknown phone';
        $theirs = $guard->device?->label;

        return [
            'title' => "Other phone: {$guard->name}",
            // One fact per line in the bell.
            'body' => "{$this->punch->type->label()} at {$this->punch->punched_at->format('g:i A')} on {$phone}".($theirs !== null ? ", not their phone ({$theirs})." : '.')
                ."\nThe punch counts. Check it was them; a new company phone can be made theirs in the Attendance Log.",
            'url' => route('attendance-log', ['q' => $guard->name, 'show' => 'other-phone']),
            'icon' => 'phone',
        ];
    }
}
