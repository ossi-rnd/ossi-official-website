<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\SentAlert;
use App\Models\User;
use App\Notifications\ProbationEnding;
use App\Queries\People\ProbationEndingQuery;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Run daily by the scheduler. Each alert goes out once, however often this runs:
 *
 *   "ending soon"             30 days before          probation.upcoming
 *   "ends today"              on the day (or up to    probation.due
 *                             a week late, worded as
 *                             "ended N days ago")
 *   "ended N days ago"        every 7 days after,     probation.overdue.1, .2, …
 *                             until HR changes the status
 */
final class SendProbationAlerts
{
    public function __construct(
        private readonly ProbationEndingQuery $query,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int how many alerts were sent
     */
    public function handle(CarbonImmutable $today): int
    {
        $profiles = $this->query->handle($today);

        if ($profiles->isEmpty()) {
            return 0;
        }

        $recipients = User::query()->notifiable(Ability::ManagePeople->roles())->get();
        $sent = 0;

        foreach ($profiles as $profile) {
            $endsOn = ProbationEndingQuery::endsOn($profile);

            if ($endsOn === null) {
                continue;
            }

            // Days since it ended: negative while still ahead. One alert name per week overdue,
            // so the weekly reminder is sent once a week (each name only once).
            $daysPast = (int) $endsOn->diffInDays($today, false);
            $alert = match (true) {
                $daysPast < 0 => 'probation.upcoming',
                $daysPast < 7 => 'probation.due',
                default => 'probation.overdue.'.intdiv($daysPast, 7),
            };

            // Claim, notify and audit together: if queueing the notification
            // fails, the claim rolls back and tomorrow's run tries again.
            $wasSent = DB::transaction(function () use ($alert, $profile, $recipients, $endsOn, $daysPast) {
                if (! SentAlert::claim($alert, $profile)) {
                    return false;
                }

                Notification::send($recipients, new ProbationEnding($profile, $endsOn, $daysPast));

                $this->audit->record(AuditArea::People, 'probation.alert_sent', "Probation alert for {$profile->name} (ends {$endsOn->format('M j, Y')})", $profile, [
                    'alert' => $alert,
                    'ends_on' => $endsOn->toDateString(),
                    'recipients' => $recipients->count(),
                ]);

                return true;
            });

            $sent += $wasSent ? 1 : 0;
        }

        return $sent;
    }
}
