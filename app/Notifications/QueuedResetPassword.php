<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's password reset email, sent from the queue. Queuing also hides a
 * timing difference: the response is equally fast whether the email exists.
 */
final class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
