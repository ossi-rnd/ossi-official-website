<?php

namespace App\Notifications;

use App\Models\LoadRequest;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Every step of a load-on-credit request. "Submitted" goes to the Admins
 * (bell only); the decision goes to the employee by bell and email.
 */
final class LoadRequestNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly LoadRequest $loadRequest,
        public readonly string $event,
    ) {
        $loadRequest->loadMissing('user');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return in_array($this->event, [self::APPROVED, self::REJECTED], true) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body())
            ->action('Open my load requests', $this->url());

        // The approver's screenshot of the load confirmation: their receipt to keep.
        $load = $this->loadRequest;
        $disk = Storage::disk((string) config('load.disk'));

        if ($this->event === self::APPROVED && $load->proof_path !== null && $disk->exists($load->proof_path)) {
            $mail->line('The load confirmation is attached as your receipt.')
                ->attachData((string) $disk->get($load->proof_path), 'load-receipt-'.$load->id.($load->proof_mime === 'image/png' ? '.png' : '.jpg'), ['mime' => (string) $load->proof_mime]);
        }

        return $mail;
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => $this->url(), 'icon' => 'banknotes'];
    }

    private function title(): string
    {
        $load = $this->loadRequest;
        $what = "₱{$load->amount} {$load->networkLabel()} load";

        return match ($this->event) {
            self::SUBMITTED => "Load request to check: {$load->user->name}",
            self::APPROVED => "Your {$what} has been sent",
            default => "Your {$what} request was rejected",
        };
    }

    private function body(): string
    {
        $load = $this->loadRequest;

        return match ($this->event) {
            self::SUBMITTED => "₱{$load->amount} {$load->networkLabel()} to {$load->formattedNumber()}, on credit (deducted from the next payslip).",
            self::APPROVED => "Sent to {$load->formattedNumber()}. ₱{$load->amount} will be deducted from your next payslip.",
            default => 'Reason: '.($load->rejection_reason ?? 'see Admin').'.',
        };
    }

    private function url(): string
    {
        return $this->event === self::SUBMITTED ? route('load-requests') : route('load');
    }
}
