<?php

namespace App\Livewire\Admin;

use App\Actions\Payroll\AdvancePayrollRun;
use App\Enums\Ability;
use App\Enums\PayrollRunStatus;
use App\Enums\Region;
use App\Livewire\Concerns\FiltersByArea;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Queries\Attendance\TodayRosterQuery;
use App\Queries\Attendance\WeeklyTrendQuery;
use App\Queries\Dashboard\DashboardHighlightsQuery;
use App\Queries\Payroll\PayrollOverviewQuery;
use App\Queries\People\BirthdaysQuery;
use App\Queries\People\WorkforceByAreaQuery;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The staff dashboard, one page for every staff role. Each section has its
 * own Ability, so each person sees the parts of their job:
 *
 *   Waiting for you   everyone: the sidebar's badges (leave to decide, relievers, complaints…)
 *   At a glance       per job: DashboardHighlightsQuery (HR's probation, Accounting's run…)
 *   People by area    view-people: region → province → client, with today's attendance
 *   Today             supervise-attendance: counts, charts and the DTR, filterable by area
 *
 * The roster is fetched once per request (#[Computed] memoizes it) and filtered
 * in PHP, since it's at most one row per employee. A Satellite Operation
 * account sees only its region's guards.
 *
 * @phpstan-import-type RosterRow from TodayRosterQuery
 * @phpstan-import-type Workforce from WorkforceByAreaQuery
 * @phpstan-import-type Tile from DashboardHighlightsQuery
 * @phpstan-import-type TrendDay from WeeklyTrendQuery
 * @phpstan-import-type PayrollOverview from PayrollOverviewQuery
 *
 * @property-read list<RosterRow> $roster
 * @property-read array{total: int, on_time: int, late: int, absent: int, clocked_in: int, on_leave: int} $stats
 * @property-read list<RosterRow> $filtered
 * @property-read list<array{label: string, route: string, icon: string, badge: int, hint: string}> $waiting
 * @property-read list<Tile> $highlights
 * @property-read Workforce $workforce
 * @property-read list<TrendDay> $trend
 * @property-read PayrollOverview $payroll
 * @property-read list<array{id: int, period: string, status: PayrollRunStatus, step: int, payDay: string, payHint: string, late: bool, payslips: int, missing: int}> $payrollRuns
 * @property-read array{rate: int|null, in: int, expected: int, change: int|null} $trendHeadline
 * @property-read list<array{profile: EmployeeProfile, on: CarbonImmutable}> $birthdays
 */
#[Title('Dashboard')]
class AttendanceDashboard extends Component
{
    use FiltersByArea, InteractsWithUser;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    #[Url(as: 'range', except: 7)]
    public int $trendDays = 7;

    public function canSeeAttendance(): bool
    {
        return $this->currentUser()->can(Ability::SuperviseAttendance->value);
    }

    public function canSeePeople(): bool
    {
        return $this->currentUser()->can(Ability::ViewPeople->value);
    }

    public function canSeePayroll(): bool
    {
        return $this->currentUser()->can(Ability::ManagePayroll->value);
    }

    /**
     * Birthdays this month that this viewer may see (BirthdaysQuery: HR all, Operations guards).
     *
     * @return list<array{profile: EmployeeProfile, on: CarbonImmutable}>
     */
    #[Computed]
    public function birthdays(): array
    {
        $today = CarbonImmutable::today();

        return app(BirthdaysQuery::class)->handle($this->currentUser(), $today->startOfMonth(), $today->endOfMonth());
    }

    /**
     * Payroll cost and deductions for Accounting: the Payroll page's own numbers.
     *
     * @return PayrollOverview
     */
    #[Computed]
    public function payroll(): array
    {
        return app(PayrollOverviewQuery::class)->handle();
    }

    /**
     * Runs still to finish, oldest first (up to 3), each with where it stands in
     * draft → processing → approved → paid, its pay day, and the payslips still
     * missing; the last paid run when none is open. What the Payroll page's
     * charts don't show: what's left to do.
     *
     * @return list<array{id: int, period: string, status: PayrollRunStatus, step: int, payDay: string, payHint: string, late: bool, payslips: int, missing: int}>
     */
    #[Computed]
    public function payrollRuns(): array
    {
        $runs = PayrollOverviewQuery::recentRuns();
        $open = $runs->filter(fn (PayrollRun $run) => $run->status !== PayrollRunStatus::Paid)->sortBy('start_date')->take(3);
        $shown = $open->isNotEmpty() ? $open : $runs->where('status', PayrollRunStatus::Paid)->take(1);
        $today = CarbonImmutable::today();
        $advance = app(AdvancePayrollRun::class);

        return array_values($shown->map(function (PayrollRun $run) use ($today, $advance): array {
            $days = (int) $today->diffInDays($run->pay_date, false);
            $paid = $run->status === PayrollRunStatus::Paid;

            return [
                'id' => $run->id,
                'period' => $run->periodLabel(),
                'status' => $run->status,
                'step' => array_search($run->status, PayrollRunStatus::cases(), true) ?: 0,
                'payDay' => $run->pay_date->format('M j'),
                'payHint' => match (true) {
                    $paid => 'paid',
                    $days < 0 => abs($days).' '.str('day')->plural(abs($days)).' overdue',
                    $days === 0 => 'today',
                    default => 'in '.$days.' '.str('day')->plural($days),
                },
                'late' => ! $paid && $days <= 0,
                'payslips' => (int) $run->payslip_records_count,
                // Paid runs are closed: nothing can be missing any more.
                'missing' => $paid ? 0 : $advance->employeesWithoutPayslip($run),
            ];
        })->all());
    }

    /**
     * What each sidebar count means, as the card's third line: what is waiting,
     * not just which page. A count added to the sidebar later still gets a card,
     * with the general line.
     */
    private const array WAITING_HINTS = [
        'leave-requests' => 'to decide', // "to endorse" for Operations: see waiting()
        'overtime-requests' => 'to review',
        'relievers' => 'posts need a reliever',
        'complaint-cases' => 'open cases',
        'resignations' => 'to accept',
        'final-pay' => 'to prepare or sign',
        'load-requests' => 'to approve',
        'daily-rates' => 'need a daily rate',
        'payroll' => 'runs to finish',
        'payroll-concerns' => 'to answer',
        'notices-to-explain' => 'to decide',
        'reimbursement-requests' => 'to approve',
        'documents' => 'expiring soon',
        'patrols' => 'guards away from post',
        'office-away' => 'out of the office now',
    ];

    /**
     * What waits for this person: the sidebar items that carry a count.
     *
     * @return list<array{label: string, route: string, icon: string, badge: int, hint: string}>
     */
    #[Computed]
    public function waiting(): array
    {
        $user = $this->currentUser();
        // Operations' step on a guard's leave is endorsing it; HR and the President or Vice President decide.
        $endorses = $user->can(Ability::EndorseGuardLeave->value) && ! $user->canAny([Ability::ReviewFieldLeave->value, Ability::ReviewOfficeLeave->value]);

        $items = collect(Navigation::for($user))->pluck('items')->flatten(1)
            ->filter(fn (array $item) => $item['badge'] > 0)
            ->map(fn (array $item) => [
                'label' => $item['label'], 'route' => $item['route'], 'icon' => $item['icon'], 'badge' => $item['badge'],
                'hint' => $item['route'] === 'leave-requests' && $endorses ? 'to endorse' : (self::WAITING_HINTS[$item['route']] ?? 'need your attention'),
            ]);

        return array_values($items->all());
    }

    /**
     * @return list<Tile>
     */
    #[Computed]
    public function highlights(): array
    {
        return app(DashboardHighlightsQuery::class)->handle($this->currentUser(), CarbonImmutable::today());
    }

    /**
     * @return list<RosterRow>
     */
    #[Computed]
    public function roster(): array
    {
        return app(TodayRosterQuery::class)->handle($this->currentUser());
    }

    /**
     * @return Workforce
     */
    #[Computed]
    public function workforce(): array
    {
        return app(WorkforceByAreaQuery::class)->handle($this->roster);
    }

    /**
     * Today's counts for the chosen area (all of it when none is chosen).
     *
     * @return array{total: int, on_time: int, late: int, absent: int, clocked_in: int, on_leave: int}
     */
    #[Computed]
    public function stats(): array
    {
        $roster = collect($this->roster)->filter(fn (array $row) => $this->rowInArea($row));

        return [
            'total' => $roster->count(),
            'on_time' => $roster->whereIn('status', ['on_time', 'undertime'])->count(),
            'late' => $roster->whereIn('status', ['late', 'late_and_undertime'])->count(),
            'absent' => $roster->where('status', 'pending')->count(),
            'clocked_in' => $roster->where('clockedIn', true)->count(),
            'on_leave' => $roster->where('status', 'on_leave')->count(),
        ];
    }

    /**
     * @return list<RosterRow>
     */
    #[Computed]
    public function filtered(): array
    {
        $needle = mb_strtolower($this->search);

        return array_values(collect($this->roster)
            ->filter(fn (array $row) => $this->rowInArea($row))
            ->filter(fn (array $row) => match ($this->statusFilter) {
                'all' => true,
                'clocked_in' => $row['clockedIn'],
                'absent' => $row['status'] === 'pending',
                'late' => in_array($row['status'], ['late', 'late_and_undertime'], true),
                default => $row['status'] === $this->statusFilter,
            })
            ->filter(fn (array $row) => $needle === '' || str_contains(mb_strtolower($row['name'].' '.$row['detachment'].' '.$row['post']), $needle))
            ->all());
    }

    /**
     * The attendance chart's range, in days, ending today (the 7D / 14D / 30D tabs).
     */
    public const array TREND_RANGES = [7, 14, 30];

    public function setTrendDays(int $days): void
    {
        $this->trendDays = in_array($days, self::TREND_RANGES, true) ? $days : 7;
    }

    /**
     * The last $trendDays days, today included.
     *
     * @return list<TrendDay>
     */
    #[Computed]
    public function trend(): array
    {
        $days = in_array($this->trendDays, self::TREND_RANGES, true) ? $this->trendDays : 7;

        return app(WeeklyTrendQuery::class)->handle(CarbonImmutable::today()->subDays($days - 1), $this->currentUser(), $days);
    }

    /**
     * The chart's headline: today's attendance rate, and the change from yesterday in points.
     *
     * @return array{rate: int|null, in: int, expected: int, change: int|null}
     */
    #[Computed]
    public function trendHeadline(): array
    {
        $trend = $this->trend;
        $today = $trend[count($trend) - 1] ?? null;
        $yesterday = $trend[count($trend) - 2] ?? null;

        return [
            'rate' => $today['rate'] ?? null,
            'in' => $today['in'] ?? 0,
            'expected' => $today['expected'] ?? 0,
            'change' => isset($today['rate'], $yesterday['rate']) ? $today['rate'] - $yesterday['rate'] : null,
        ];
    }

    /**
     * What today's numbers cover, in words: the area picked in the Client site bar, else
     * what this person sees at all ("whole company", or a Satellite Operation's region).
     */
    public function scopeLabel(): string
    {
        $region = Region::tryFrom($this->areaRegion);

        return match (true) {
            $this->areaRegion === self::OFFICE => 'office staff',
            $this->areaClient !== '' => (string) (Client::query()->whereKey((int) $this->areaClient)->value('name') ?? 'this client'),
            $this->areaProvince !== '' => $this->areaProvince,
            $region !== null => $region->label(),
            default => $this->viewerScope(),
        };
    }

    /**
     * What this person's dashboard counts at all (User::scopeOnDashboardOf): "whole company";
     * for Operations, guards only: "all guards", or "guards · NCR" for a Satellite Operation.
     */
    public function viewerScope(): string
    {
        $user = $this->currentUser();
        $region = $user->operations_region?->label() ?? 'no region set';

        if (! $this->canSeeOfficeStaff()) {
            return 'all guards';
        }

        return $user->isRegionallyLimited() ? $region : 'whole company';
    }

    /** Every region is on this page, also for a Satellite Operation (guards only). */
    protected function limitsAreaToOwnRegion(): bool
    {
        return false;
    }

    /**
     * Whether a person or area in this region may be opened from here: a Satellite
     * Operation sees every guard on the dashboard, but records in its region only.
     */
    public function opensRegion(?string $region): bool
    {
        $user = $this->currentUser();

        return ! $user->isRegionallyLimited() || ($region !== null && $region === $user->operations_region?->value);
    }

    /** Office staff in the numbers (and as a choice in the Client site bar), or guards only. */
    public function canSeeOfficeStaff(): bool
    {
        return $this->currentUser()->can(Ability::ViewOfficeStaff->value);
    }

    /** "Good morning" / "Good afternoon" / "Good evening", by the clock. */
    public function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    public function render(): View
    {
        return view('livewire.admin.attendance-dashboard');
    }
}
