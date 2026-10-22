<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's verification email, sent from the queue so registration doesn't
 * wait on Gmail's SMTP handshake (often 1-3 seconds).
 */
final class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
