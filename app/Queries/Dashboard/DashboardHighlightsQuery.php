<?php

namespace App\Queries\Dashboard;

use App\Enums\Ability;
use App\Enums\AbsenceSource;
use App\Enums\EmploymentStatus;
use App\Enums\PayrollRunStatus;
use App\Models\AbsenceNotice;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\Training;
use App\Models\User;
use App\Queries\People\ProbationEndingQuery;
use Carbon\CarbonImmutable;

/**
 * The "at a glance" tiles: a few numbers per job, each shown only to those
 * whose Ability covers it (never by role name), so a person with two roles
 * gets both sets. What already waits for a decision is the sidebar badges'
 * job ("Waiting for you"), so it isn't repeated here.
 *
 * @phpstan-type Tile array{group: string, label: string, value: string, hint: string|null, url: string, tone: string, icon: string}
 */
final class DashboardHighlightsQuery
{
    public function __construct(private readonly ProbationEndingQuery $probation) {}

    /**
     * @return list<Tile>
     */
    public function handle(User $user, CarbonImmutable $today): array
    {
        $tiles = [];
        $can = fn (Ability $ability): bool => $user->can($ability->value);

        // The President or Vice President: the company in three numbers.
        if ($can(Ability::ViewOrganization)) {
            $active = EmployeeProfile::query()->active();
            $guards = (clone $active)->where('is_office_staff', false)->count();
            $office = (clone $active)->where('is_office_staff', true)->count();
            $clients = Client::query()->count();
            $regions = Client::query()->distinct()->count('region');

            // Everyone employed, which is more than the Headcount below: that one counts who clocks in,
            // so the hint explains the gap in its words (the rest: no account yet, or the President or Vice President).
            $clockIn = EmployeeProfile::query()->paidHere()->whereNotNull('user_id')->count();
            $noAccount = (clone $active)->whereNull('user_id')->count();
            $tiles[] = self::tile('Company', 'Employees', (string) ($guards + $office), "{$clockIn} clock in".($noAccount > 0 ? " · {$noAccount} no account yet" : ''), route('organization'), icon: 'users');
            $tiles[] = self::tile('Company', 'Clients', (string) $clients, $regions === 1 ? 'in 1 region' : "in {$regions} regions", route('organization'), icon: 'building');
        }

        // HR: people coming in, still to sign up, and probation decisions.
        if ($can(Ability::ManagePeople)) {
            $ending = $this->probation->handle($today)->count();
            $hired = EmployeeProfile::query()->whereBetween('hire_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])->count();
            $noAccount = EmployeeProfile::query()->active()->whereNull('user_id')->count();

            // The count includes those already past it; the hint says how many, since they're regular by law (Art. 296).
            $overdue = EmployeeProfile::query()->probationOverdue()->count();
            $tiles[] = self::tile('People', 'Probation ending', (string) $ending, $overdue > 0 ? "{$overdue} already past ".config('hr.probation.months').' months' : 'within '.config('hr.probation.remind_days_before').' days', route('employees', ['status' => EmploymentStatus::Probationary->value]), $ending > 0 ? 'amber' : 'gray', 'clock');
            $tiles[] = self::tile('People', 'Hired this month', (string) $hired, $today->format('F Y'), route('employees'), icon: 'user-plus');
            $tiles[] = self::tile('People', 'No account yet', (string) $noAccount, 'not registered', route('employees', ['account' => 'unregistered']), $noAccount > 0 ? 'amber' : 'gray', 'user');
        }

        // Accounting: where the latest payroll run stands, and when pay goes out next.
        if ($can(Ability::ManagePayroll)) {
            $run = PayrollRun::query()->latest('start_date')->first();
            $tiles[] = $run === null
                ? self::tile('Payroll', 'Latest payroll run', 'None yet', 'Create one on Payroll', route('payroll'), icon: 'banknotes')
                : self::tile('Payroll', 'Latest payroll run', $run->status->label(), $run->periodLabel(), route('payroll', ['run' => $run->id]), icon: 'banknotes');

            $next = PayrollRun::query()->where('status', '!=', PayrollRunStatus::Paid)->orderBy('pay_date')->first();
            $tiles[] = $next === null
                ? self::tile('Payroll', 'Next pay day', '—', 'no unpaid run', route('payroll'), icon: 'calendar')
                : self::nextPayDay($next, $today);
        }

        // Operations: today's absences, from reported to covered (one query for all three).
        if ($can(Ability::ManageRelievers)) {
            $notices = AbsenceNotice::query()->visibleTo($user)->whereDate('duty_date', $today->toDateString())->get(['source', 'reliever_id']);
            $reported = $notices->where('source', AbsenceSource::Guard)->count();
            $noShows = $notices->where('source', AbsenceSource::NoShow)->count();
            $covered = $notices->whereNotNull('reliever_id')->count();

            $tiles[] = self::tile('Operations', 'Absences reported', (string) $reported, 'told ahead today', route('relievers'), icon: 'calendar');
            $tiles[] = self::tile('Operations', 'No-shows', (string) $noShows, 'didn\'t clock in or report', route('relievers'), $noShows > 0 ? 'red' : 'gray', 'clock-alert');
            $tiles[] = self::tile('Operations', 'Relievers sent', (string) $covered, $notices->isEmpty() ? 'no absences today' : "{$covered} of {$notices->count()} posts covered", route('relievers'), icon: 'arrows-right-left');
        }

        // Admin: training coming up.
        if ($can(Ability::ManageTraining)) {
            $upcoming = Training::query()->whereBetween('starts_on', [$today->toDateString(), $today->addDays(14)->toDateString()])->count();
            $tiles[] = self::tile('Admin', 'Training, next 14 days', (string) $upcoming, null, route('trainings'), icon: 'academic-cap');
        }

        return $tiles;
    }

    /**
     * "Oct 20 · in 5 days": amber within 3 days, red on the day or overdue (still unpaid).
     *
     * @return Tile
     */
    private static function nextPayDay(PayrollRun $run, CarbonImmutable $today): array
    {
        $days = (int) $today->diffInDays($run->pay_date, false);

        $hint = match (true) {
            $days < 0 => abs($days).' '.str('day')->plural(abs($days)).' overdue',
            $days === 0 => 'today',
            default => 'in '.$days.' '.str('day')->plural($days),
        };

        return self::tile('Payroll', 'Next pay day', $run->pay_date->format('M j'), $hint, route('payroll', ['run' => $run->id]), $days <= 0 ? 'red' : ($days <= 3 ? 'amber' : 'gray'), 'calendar');
    }

    /**
     * @return Tile
     */
    private static function tile(string $group, string $label, string $value, ?string $hint, string $url, string $tone = 'gray', string $icon = 'info'): array
    {
        return ['group' => $group, 'label' => $label, 'value' => $value, 'hint' => $hint, 'url' => $url, 'tone' => $tone, 'icon' => $icon];
    }
}
