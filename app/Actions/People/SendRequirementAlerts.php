<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\EmploymentStatus;
use App\Enums\RequirementStatus;
use App\Enums\Role;
use App\Models\EmployeeRequirement;
use App\Models\SentAlert;
use App\Models\User;
use App\Notifications\RequirementNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Run daily. Two kinds of alert, each sent once:
 *
 * 1. Expiry: at 60, 30 and 7 days before and on the day (config). Only the
 *    nearest threshold is sent, so a missed week never sends 60 and 30
 *    together. The alert key includes the expiry date, so a renewed
 *    document (new date) gets its own alerts.
 * 2. Promise overdue: a missing document whose promised date has passed.
 *
 * Recipients: the employee, HR, and Admin (operations).
 */
final class SendRequirementAlerts
{
    /**
     * @return int how many alerts were sent
     */
    public function handle(CarbonImmutable $today): int
    {
        $staff = User::query()->notifiable([...Ability::ManagePeople->roles(), Role::Admin])->get();

        return $this->expiryAlerts($today, $staff) + $this->promiseAlerts($today, $staff);
    }

    /**
     * @param  Collection<int, User>  $staff
     */
    private function expiryAlerts(CarbonImmutable $today, Collection $staff): int
    {
        /** @var list<int> $thresholds */
        $thresholds = config('hr.documents.expiry_alert_days');

        // An empty list in config switches expiry alerts off.
        if ($thresholds === []) {
            return 0;
        }

        sort($thresholds);

        $records = $this->activeEmployees(EmployeeRequirement::query())
            ->where('status', RequirementStatus::OnFile)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $today->addDays((int) max($thresholds))->toDateString())
            ->get();

        $sent = 0;

        foreach ($records as $record) {
            $daysLeft = (int) $record->daysToExpiry($today);
            $threshold = $this->nearestThreshold($daysLeft, $thresholds);
            $alert = sprintf('requirement.expiry.%d.%s', $threshold, $record->expires_on?->format('Ymd'));
            $reason = $daysLeft < 0 ? RequirementNotice::EXPIRED : RequirementNotice::EXPIRING;

            $sent += $this->send($alert, $record, new RequirementNotice($record, $reason, email: $threshold <= 30), $staff) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * @param  Collection<int, User>  $staff
     */
    private function promiseAlerts(CarbonImmutable $today, Collection $staff): int
    {
        $records = $this->activeEmployees(EmployeeRequirement::query())
            ->where('status', RequirementStatus::Missing)
            ->whereNotNull('promised_on')
            ->whereDate('promised_on', '<', $today->toDateString())
            ->get();

        $sent = 0;

        foreach ($records as $record) {
            $alert = 'requirement.promise.'.$record->promised_on?->format('Ymd');
            $sent += $this->send($alert, $record, new RequirementNotice($record, RequirementNotice::PROMISE_OVERDUE), $staff) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * @param  Collection<int, User>  $staff
     */
    private function send(string $alert, EmployeeRequirement $record, RequirementNotice $notice, Collection $staff): bool
    {
        return DB::transaction(function () use ($alert, $record, $notice, $staff) {
            if (! SentAlert::claim($alert, $record)) {
                return false;
            }

            $recipients = $staff->collect();

            if ($record->profile->user !== null) {
                $recipients->push($record->profile->user);
            }

            Notification::send($recipients->unique('id')->values(), $notice);

            return true;
        });
    }

    /**
     * The smallest threshold the remaining days fall under: 25 days left → 30.
     *
     * @param  list<int>  $thresholds  ascending
     */
    private function nearestThreshold(int $daysLeft, array $thresholds): int
    {
        foreach ($thresholds as $threshold) {
            if ($daysLeft <= $threshold) {
                return $threshold;
            }
        }

        return (int) end($thresholds);
    }

    /**
     * Documents of people who still work here, with the profile and account loaded.
     *
     * @param  Builder<EmployeeRequirement>  $query
     * @return Builder<EmployeeRequirement>
     */
    private function activeEmployees(Builder $query): Builder
    {
        return $query
            ->whereHas('profile', fn (Builder $profile) => $profile->whereIn('employment_status', EmploymentStatus::active()))
            ->with('profile.user');
    }
}
