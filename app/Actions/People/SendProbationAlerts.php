<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\ProbationEvaluation;
use App\Models\SentAlert;
use App\Models\User;
use App\Notifications\ProbationEnding;
use App\Queries\People\ProbationEndingQuery;
use App\Services\AuditLogger;
use App\Support\Heads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Run daily by the scheduler, after ApplyProbationEnds. To HR and the employee's head (who
 * evaluates; HR approves). Each alert goes out once, however often this runs:
 *
 *   "ending soon"             the rules' days before    probation.upcoming
 *   "ends today"              on the day                probation.due
 *   "ended N days ago"        only if the daily close   probation.overdue.1, .2, …
 *                             didn't run (it makes them Regular)
 *
 * Nobody is reminded once a decision was approved.
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

        $hr = User::query()->notifiable(Ability::ManagePeople->roles())->get();
        $sent = 0;

        foreach ($profiles as $profile) {
            $endsOn = ProbationEndingQuery::endsOn($profile);

            if ($endsOn === null || $profile->probationEvaluations()->where('status', ProbationEvaluation::APPROVED)->exists()) {
                continue;
            }

            // HR, and the head who evaluates (Operations for a guard); never the employee.
            $recipients = $hr->merge($profile->user === null ? [] : Heads::of($profile->user))
                ->unique('id')->reject(fn (User $user) => $user->id === $profile->user_id)->values();

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
