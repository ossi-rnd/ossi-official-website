<?php

namespace App\Notifications;

use App\Models\Referral;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Referrals: a new one (to HR), and hired / not hired / no bonus (to the referrer). Bell only.
 */
final class ReferralNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const SUBMITTED = 'submitted';

    public const HIRED = 'hired';

    public const NOT_HIRED = 'not_hired';

    public const FORFEITED = 'forfeited';

    public function __construct(
        public readonly Referral $referral,
        public readonly string $event,
    ) {}

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
        $who = $this->referral->applicant_name;
        $job = $this->referral->position?->label();

        [$title, $body, $url] = match ($this->event) {
            self::SUBMITTED => ["New referral: {$who}", "{$this->referral->referrer->name} referred them".($job ? " for {$job}" : '').". Contact: {$this->referral->applicant_phone}.", route('referral-cases')],
            self::HIRED => ["{$who} was hired", 'Your referral bonus of ₱'.number_format((float) $this->referral->bonus_amount, 2).' comes on your payslip after '.$this->referral->bonus_due_on?->format('M j, Y').', if they still work here then.', route('referrals')],
            self::NOT_HIRED => ["{$who} wasn't hired", "HR: {$this->referral->decision_note}", route('referrals')],
            default => ["No referral bonus for {$who}", 'They left before '.$this->referral->bonus_due_on?->format('M j, Y').', the day the bonus was due.', route('referrals')],
        };

        return ['title' => $title, 'body' => $body, 'url' => $url, 'icon' => 'user-plus'];
    }
}
