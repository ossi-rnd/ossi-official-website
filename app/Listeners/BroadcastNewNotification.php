<?php

namespace App\Listeners;

use App\Events\NotificationArrived;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * Every notification that lands in the bell (the "database" channel) is also
 * pushed live. One listener instead of a "broadcast" channel on each
 * notification class, so new notifications are live without remembering to.
 */
final class BroadcastNewNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User || ! $event->response instanceof DatabaseNotification) {
            return;
        }

        try {
            event(new NotificationArrived($event->notifiable, $event->response));
        } catch (Throwable $e) {
            // The row is saved; the bell shows it on the next page load. Failing here would
            // retry the notification's job and write the row (and send the email) twice.
            report($e);
        }
    }
}
