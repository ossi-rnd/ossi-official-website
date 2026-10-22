<?php

namespace App\Notifications;

use App\Models\EmployeeProfile;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells HR that an employee's probation ends soon, ends today, or ended and
 * is still undecided (weekly), so the regularization decision is made: under
 * Labor Code Art. 296, someone allowed to keep working after probation is
 * regular by law, whatever their record says.
 */
final class ProbationEnding extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    /** Ends today or already ended. */
    public readonly bool $isDue;

    /**
     * @param  int  $daysPast  days since probation ended: negative = still ahead, 0 = today
     */
    public function __construct(
        public readonly EmployeeProfile $profile,
        public readonly CarbonImmutable $endsOn,
        public readonly int $daysPast,
    ) {
        $this->isDue = $daysPast >= 0;
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body())
            ->action('Open employee record', $this->url())
            ->line('Update the employment status once a decision is made.');
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => $this->url(), 'icon' => 'user'];
    }

    private function title(): string
    {
        return match (true) {
            $this->daysPast < 0 => "Probation ending soon: {$this->profile->name}",
            $this->daysPast === 0 => "Probation ends today: {$this->profile->name}",
            default => 'Probation ended '.$this->daysPast.' '.str('day')->plural($this->daysPast)." ago: {$this->profile->name}",
        };
    }

    private function body(): string
    {
        $months = (int) config('hr.probation.months');
        $who = "{$this->profile->name} ({$this->profile->employee_number})";

        if ($this->daysPast > 0) {
            return "{$who} passed {$months} months of probation on {$this->endsOn->format('M j, Y')} and is still marked Probationary. "
                .'Under Labor Code Art. 296, an employee allowed to keep working after probation becomes regular. Decide now.';
        }

        return "{$who} reaches {$months} months of probation on {$this->endsOn->format('M j, Y')}. Decide on regularization.";
    }

    private function url(): string
    {
        return route('employees.show', $this->profile);
    }
}
