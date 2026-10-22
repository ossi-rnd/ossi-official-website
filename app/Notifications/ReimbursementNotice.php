<?php

namespace App\Notifications;

use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reimbursements. Filed: to the President or Vice President. Decided: to the employee (and to
 * Operations, for a deployment claim they filed). Approved: to Accounting too
 * (it's paid on the next payslip). Filed for you: to a guard whose deployment
 * cost Operations claimed.
 */
final class ReimbursementNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const FILED = 'filed';

    public const DECIDED = 'decided';

    public const TO_PAY = 'to_pay';

    public const FILED_FOR_YOU = 'filed_for_you';

    public function __construct(
        public readonly Reimbursement $reimbursement,
        public readonly string $event,
    ) {
        $reimbursement->loadMissing(['user', 'filer']);
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
        $mail = (new MailMessage)->subject($this->title($notifiable))->greeting("Hello {$notifiable->name},");

        foreach ($this->lines($notifiable) as $line) {
            $mail->line($line);
        }

        return $mail->action('Open', $this->url($notifiable));
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(User $notifiable): array
    {
        return ['title' => $this->title($notifiable), 'body' => implode(' ', $this->lines($notifiable)), 'url' => $this->url($notifiable), 'icon' => 'banknotes'];
    }

    private function amount(): string
    {
        return '₱'.number_format((float) $this->reimbursement->amount, 2);
    }

    /** Operations reading about a claim they filed for a guard. */
    private function forFiler(User $notifiable): bool
    {
        return $this->reimbursement->filedForThem() && $notifiable->id === $this->reimbursement->filed_by;
    }

    private function title(User $notifiable): string
    {
        $r = $this->reimbursement;
        $approved = $r->status === ReimbursementStatus::Approved;

        return match (true) {
            $this->event === self::FILED => $r->filedForThem()
                ? "Deployment claim to approve: {$r->user->name}, {$this->amount()}"
                : "Reimbursement to approve: {$r->user->name}, {$this->amount()}",
            $this->event === self::TO_PAY => "Reimbursement approved: {$r->user->name}, {$this->amount()}",
            $this->event === self::FILED_FOR_YOU => "A deployment cost claimed for you: {$this->amount()}",
            $this->forFiler($notifiable) => ($approved ? 'Deployment claim approved: ' : 'Deployment claim rejected: ')."{$r->user->name}, {$this->amount()}",
            default => $approved ? "Reimbursement approved: {$this->amount()}" : "Reimbursement rejected: {$this->amount()}",
        };
    }

    /**
     * @return list<string>
     */
    private function lines(User $notifiable): array
    {
        $r = $this->reimbursement;
        $what = "{$r->category->short()}, {$r->spent_on->format('M j')}: {$r->purpose}".($r->deployment_for ? " ({$r->deployment_for})" : '');
        $by = $r->filedForThem() ? 'Filed by '.($r->filer->name ?? 'Operations').'.' : null;

        return array_values(array_filter(match (true) {
            $this->event === self::FILED => [$what, $by],
            $this->event === self::TO_PAY => [$what, $by, 'It\'s added to their next payslip when it\'s generated.'],
            $this->event === self::FILED_FOR_YOU => [$what, $by, 'Once the President or Vice President approves it, it\'s added to your next payslip, not taxed.'],
            $r->status === ReimbursementStatus::Approved => [$what, $this->forFiler($notifiable) ? 'It\'s added to their next payslip, not taxed.' : 'It\'s added to your next payslip, not taxed.'],
            default => [$what, 'Reason: '.$r->decision_note],
        }));
    }

    private function url(User $notifiable): string
    {
        return match (true) {
            $this->forFiler($notifiable) => route('deployment-claims'),
            in_array($this->event, [self::DECIDED, self::FILED_FOR_YOU], true) => route('reimbursements'),
            default => route('reimbursement-requests', ['open' => $this->reimbursement->id]),
        };
    }
}
