<?php

namespace App\Notifications;

use App\Models\Complaint;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bell only, and never the complaint's text: the handler (HR, or the
 * Executive for HR staff's) learns one was filed, the
 * employee learns its status changed. The details stay in the app.
 * A complaint asking for help also emails the handler, still without its text.
 */
final class ComplaintNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    /** The complainant added proof (to the handler). */
    public const PROOF_ADDED = 'proof_added';

    /** The handler asks for proof (to the complainant). */
    public const PROOF_REQUESTED = 'proof_requested';

    public function __construct(public readonly Complaint $complaint, public readonly bool $forHr, public readonly ?string $event = null)
    {
        $complaint->loadMissing('user');
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        // Someone asking for help can't wait for the handler to open the app: email too.
        return $this->isHelpRequest() ? ['database', 'mail'] : ['database'];
    }

    private function isHelpRequest(): bool
    {
        return $this->forHr && $this->event === null && $this->complaint->asks_for_help;
    }

    /** Still nothing of what it says: who, the reference, and to open it now. */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Urgent: {$this->complaint->user->name} asks for help ({$this->complaint->reference()})")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->complaint->user->name} filed a complaint and asked for help now.")
            ->line('The details are in the app only. Please open the case and reach them as soon as you can.')
            ->action('Open the case', route('complaint-cases', ['open' => $this->complaint->id]));
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        $reference = $this->complaint->reference();
        $handler = ucfirst($this->complaint->handlerLabel());

        // Still no contents: that proof came or is wanted, not what it is.
        if ($this->event === self::PROOF_ADDED) {
            return ['title' => "Proof added to {$reference}", 'body' => "{$this->complaint->user->name} attached proof to their complaint.", 'url' => route('complaint-cases', ['open' => $this->complaint->id]), 'icon' => 'flag'];
        }

        if ($this->event === self::PROOF_REQUESTED) {
            return ['title' => "Your complaint {$reference}: proof asked for", 'body' => "{$handler} asks for proof, if you have any. Open My Complaints to see what and to add it.", 'url' => route('complaints'), 'icon' => 'flag'];
        }

        if ($this->isHelpRequest()) {
            return ['title' => "Urgent: {$this->complaint->user->name} asks for help", 'body' => "Complaint {$reference} ({$this->complaint->category->label()}). Open it and reach them now.", 'url' => route('complaint-cases', ['open' => $this->complaint->id]), 'icon' => 'flag'];
        }

        return $this->forHr
            ? ['title' => "New complaint {$reference}", 'body' => "{$this->complaint->user->name} filed a complaint ({$this->complaint->category->label()}).", 'url' => route('complaint-cases', ['open' => $this->complaint->id]), 'icon' => 'flag']
            : ['title' => "Your complaint {$reference}: {$this->complaint->status->label()}", 'body' => $this->complaint->status->isClosed() ? "{$handler} wrote the outcome. Open My Complaints to read it." : "{$handler} is looking into it.", 'url' => route('complaints'), 'icon' => 'flag'];
    }
}
