<?php

namespace App\Notifications;

use App\Models\PettyCashReplenishment;
use App\Models\User;
use App\Support\WritesBellImmediately;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Petty cash replenishments: asked for (to Accounting), released or returned (to the custodian). Bell only.
 */
final class PettyCashNotice extends Notification implements ShouldQueue
{
    use Queueable, WritesBellImmediately;

    public const REQUESTED = 'requested';

    public const RELEASED = 'released';

    public const RETURNED = 'returned';

    public function __construct(
        public readonly PettyCashReplenishment $request,
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
        $fund = $this->request->fund->name;
        $amount = '₱'.number_format((float) $this->request->amount, 2);

        [$title, $body] = match ($this->event) {
            self::REQUESTED => ["Replenish petty cash: {$fund}", "{$amount} spent, with the receipts. Release it on Petty Cash."],
            self::RELEASED => ["Petty cash replenished: {$fund}", "Accounting released {$amount}. The fund is whole again."],
            default => ["Replenishment returned: {$fund}", "Accounting returned the {$amount} request: {$this->request->decision_note}"],
        };

        return ['title' => $title, 'body' => $body, 'url' => route('petty-cash', ['fund' => $this->request->petty_cash_fund_id]), 'icon' => 'wallet'];
    }
}
