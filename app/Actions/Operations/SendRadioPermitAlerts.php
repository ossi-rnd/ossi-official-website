<?php

namespace App\Actions\Operations;

use App\Enums\Ability;
use App\Models\Radio;
use App\Models\SentAlert;
use App\Models\User;
use App\Notifications\RadioPermitDue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Run daily: radios whose NTC permit nears expiry, to Admin and Operation HQ
 * (manage-radios). The same steps as company documents (config documents.expiry_alert_days):
 * only the nearest step is sent, once per radio and expiry date, so a renewed permit
 * (a new date) gets its own alerts.
 */
final class SendRadioPermitAlerts
{
    /**
     * @return int how many alerts were sent
     */
    public function handle(CarbonImmutable $today): int
    {
        /** @var list<int> $steps */
        $steps = config('documents.expiry_alert_days');

        if ($steps === []) {
            return 0;
        }

        sort($steps);

        $recipients = User::query()->notifiable(Ability::ManageRadios->roles())->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $sent = 0;

        foreach (Radio::query()->permitDueBy($today->addDays((int) max($steps)))->get() as $radio) {
            $daysLeft = (int) $radio->daysToExpiry($today);
            $step = collect($steps)->first(fn (int $days) => $daysLeft <= $days) ?? (int) end($steps);
            $alert = sprintf('radio.permit.%d.%s', $step, $radio->permit_expires_on?->format('Ymd'));

            $sent += DB::transaction(function () use ($alert, $radio, $daysLeft, $step, $recipients) {
                if (! SentAlert::claim($alert, $radio)) {
                    return 0;
                }

                Notification::send($recipients, new RadioPermitDue($radio, $daysLeft, email: $step <= 30));

                return 1;
            });
        }

        return $sent;
    }
}
