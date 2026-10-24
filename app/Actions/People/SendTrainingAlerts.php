<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\EmploymentStatus;
use App\Models\EmployeeProfile;
use App\Models\SentAlert;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use App\Support\Heads;
use App\Support\ProbationRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Run daily with the probation alerts. A Trainee whose training length (HR's rules, from the
 * hire date) is up: HR and their head are told to move them on (Probationary, usually), on
 * the day and then once a week while they're still a Trainee. Nothing changes by itself.
 *
 *   on the day (or up to a week late)   training.due
 *   every 7 days after                  training.overdue.1, .2, …
 */
final class SendTrainingAlerts
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return int how many alerts were sent
     */
    public function handle(CarbonImmutable $today): int
    {
        $days = ProbationRules::trainingDays();
        $profiles = EmployeeProfile::query()->with('user')
            ->where('employment_status', EmploymentStatus::Trainee)
            ->whereNotNull('hire_date')
            ->whereDate('hire_date', '<=', $today->subDays($days)->toDateString())
            ->orderBy('hire_date')
            ->get();

        if ($profiles->isEmpty()) {
            return 0;
        }

        $hr = User::query()->notifiable(Ability::ManagePeople->roles())->get();
        $sent = 0;

        foreach ($profiles as $profile) {
            $endsOn = ProbationRules::trainingEndsOn($profile);

            if ($endsOn === null) {
                continue;
            }

            $daysPast = (int) $endsOn->diffInDays($today, false);
            $alert = $daysPast < 7 ? 'training.due' : 'training.overdue.'.intdiv($daysPast, 7);
            $recipients = $hr->merge($profile->user === null ? [] : Heads::of($profile->user))
                ->unique('id')->reject(fn (User $user) => $user->id === $profile->user_id)->values();

            $wasSent = DB::transaction(function () use ($alert, $profile, $recipients, $endsOn, $daysPast, $days) {
                if (! SentAlert::claim($alert, $profile)) {
                    return false;
                }

                $title = $daysPast === 0 ? "Training ends today: {$profile->name}" : "Training ended {$daysPast} ".str('day')->plural($daysPast)." ago: {$profile->name}";
                foreach ($recipients as $user) {
                    UpdateNotice::tell($user, null, $title, "{$profile->name} has trained {$days} days (from the hire date) and is still a Trainee. HR: change their status, usually to Probationary.", route('employees.show', $profile), 'user', email: true);
                }

                $this->audit->record(AuditArea::People, 'training.alert_sent', "Training alert for {$profile->name} (ended {$endsOn->format('M j, Y')})", $profile, ['alert' => $alert, 'recipients' => $recipients->count()]);

                return true;
            });

            $sent += $wasSent ? 1 : 0;
        }

        return $sent;
    }
}
