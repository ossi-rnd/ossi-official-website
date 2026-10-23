<?php

namespace App\Livewire\Admin;

use App\Actions\Patrols\ApproveAway;
use App\Enums\Ability;
use App\Enums\AwayReason;
use App\Enums\Region;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\AwayLog;
use App\Queries\Attendance\AwayTrailQuery;
use App\Support\Heads;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Who is away from work right now, why, for how long and where: the list,
 * the location window (map, address, trail) and Approve.
 *
 * Two audiences, like leave:
 *  - 'field':  guards away from their post, for Operations (embedded on
 *              Patrols; a Satellite Operation sees its region);
 *  - 'office': office staff out of the office, for the President or Vice President
 *              (the Office Away page).
 *
 * The list is sorted into status tabs (needs attention first) and filtered
 * by name, reason and region, so it stays readable with hundreds out.
 *
 * @property-read Collection<int, AwayLog> $openAways
 * @property-read array<string, int> $statusCounts
 * @property-read Collection<int, AwayLog> $awayNow
 * @property-read LengthAwarePaginator<int, AwayLog> $awayToday
 * @property-read AwayLog|null $viewingAway
 * @property-read array{post: array{lat: float, lng: float, radius: int, name: string}|null, points: list<array{lat: float|null, lng: float|null, accuracy: int|null, label: string, kind: string, at: CarbonImmutable, distance: int|null, mapUrl: string|null}>, address: string|null, latest: array{lat: float|null, lng: float|null, accuracy: int|null, label: string, kind: string, at: CarbonImmutable, distance: int|null, mapUrl: string|null}}|null $trail
 */
#[Title('Office Away')]
class AwayMonitor extends Component
{
    use InteractsWithUser, WithPagination;

    /**
     * The status tabs over the people out now. "Needs attention" is narrow on
     * purpose: no location for a while, or well past their own time.
     */
    public const STATUSES = [
        'all' => 'All',
        'attention' => 'Needs attention',
        'late' => 'Past their time',
        'overdue' => 'Location overdue',
        'approved' => 'Approved',
    ];

    #[Locked]
    public string $scope = 'office';

    /** The absence whose location window is open. */
    public ?int $viewingAwayId = null;

    public string $status = 'all';

    public string $search = '';

    /** An AwayReason value, or '' for every reason. */
    public string $reason = '';

    /** A Region value, or '' for every region (guards only). */
    public string $region = '';

    /** The day of the "back" list, Y-m-d; '' = today. Earlier days are the history. */
    public string $day = '';

    public function previousDay(): void
    {
        $this->day = $this->shownDay()->subDay()->toDateString();
        $this->resetPage('back');
    }

    public function nextDay(): void
    {
        $next = $this->shownDay()->addDay();
        $this->day = $next->isToday() || $next->isFuture() ? '' : $next->toDateString();
        $this->resetPage('back');
    }

    /** The day shown: a valid past date, else today (a typed URL can't ask for the future). */
    public function shownDay(): CarbonImmutable
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->day) === 1 ? CarbonImmutable::createFromFormat('Y-m-d', $this->day) : false;

        return $day instanceof CarbonImmutable && $day->startOfDay()->lessThan(CarbonImmutable::today()) ? $day->startOfDay() : CarbonImmutable::today();
    }

    public function mount(string $scope = 'office'): void
    {
        $this->scope = $scope === 'field' ? 'field' : 'office';
        $this->authorize($this->ability()->value);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'reason', 'region', 'day'], true)) {
            $this->resetPage('back');
        }
    }

    public function approve(int $awayId, ApproveAway $approve): void
    {
        $this->authorize($this->ability()->value);

        $approve->handle($this->scoped()->findOrFail($awayId), $this->currentUser());
        unset($this->openAways, $this->statusCounts, $this->awayNow, $this->awayToday, $this->viewingAway, $this->trail);
        $this->dispatch('notify', message: 'Time away approved.');
    }

    public function viewAway(int $awayId): void
    {
        $this->authorize($this->ability()->value);

        $this->viewingAwayId = $this->scoped()->findOrFail($awayId)->id;
        unset($this->viewingAway, $this->trail);
    }

    public function closeAway(): void
    {
        $this->reset('viewingAwayId');
    }

    private function ability(): Ability
    {
        return $this->scope === 'office' ? Ability::MonitorOfficeAway : Ability::ManagePatrols;
    }

    /**
     * Absences this audience covers: guards in the viewer's reach, or office staff (an account
     * with no HR profile counts as office staff): all of them for the President or VP and the
     * Super Admin, else only those the viewer heads (Heads: HR, or Operation HQ for Satellites).
     *
     * @return Builder<AwayLog>
     */
    private function scoped(): Builder
    {
        $viewer = $this->currentUser();

        return AwayLog::query()->whereHas('user', fn (Builder $user) => match (true) {
            $this->scope !== 'office' => $user->visibleTo($viewer)->whereHas('employeeProfile', fn (Builder $profile) => $profile->where('is_office_staff', false)),
            Heads::seesAllOfficeStaff($viewer) => $user->officeStaff(),
            default => Heads::constrainOfficeStaffHeadedBy($user, $viewer),
        });
    }

    /** Regions are a filter only for guards, and only for viewers who see more than one. */
    public function filtersByRegion(): bool
    {
        return $this->scope === 'field' && ! $this->currentUser()->isRegionallyLimited();
    }

    /**
     * The audience, narrowed by the search, reason and region filters.
     *
     * @return Builder<AwayLog>
     */
    private function filtered(): Builder
    {
        $search = trim($this->search);
        $reason = AwayReason::tryFrom($this->reason);
        $region = $this->filtersByRegion() ? Region::tryFrom($this->region) : null;

        return $this->scoped()
            ->when($search !== '', fn (Builder $query) => $query->whereHas('user', fn (Builder $user) => $user->where('name', 'like', '%'.$search.'%')))
            ->when($reason !== null, fn (Builder $query) => $query->where('reason', $reason?->value))
            // The post they're rostered at today, else the client they're deployed to.
            ->when($region !== null, fn (Builder $query) => $query->where(fn (Builder $where) => $where
                ->whereHas('timeLog.dutyAssignment.client', fn (Builder $client) => $client->where('region', $region?->value))
                ->orWhere(fn (Builder $undeployed) => $undeployed
                    ->whereDoesntHave('timeLog.dutyAssignment.client')
                    ->whereHas('user.employeeProfile.client', fn (Builder $client) => $client->where('region', $region?->value)))));
    }

    /**
     * Everyone out right now who matches the filters, before the status tab.
     * Always a small set (only open absences), so the status is worked out here.
     *
     * @return Collection<int, AwayLog>
     */
    #[Computed]
    public function openAways(): Collection
    {
        return $this->filtered()->open()
            ->with(['user.employeeProfile', 'timeLog.dutyAssignment.client', 'approver', 'latestLocation'])
            ->withCount('extensions')
            ->orderBy('left_at')
            ->get();
    }

    /**
     * How many fall under each status tab.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $now = CarbonImmutable::now();

        return collect(array_keys(self::STATUSES))
            ->mapWithKeys(fn (string $status) => [$status => $this->openAways->filter(fn (AwayLog $away) => self::hasStatus($away, $status, $now))->count()])
            ->all();
    }

    private static function hasStatus(AwayLog $away, string $status, CarbonImmutable $now): bool
    {
        return match ($status) {
            'attention' => $away->needsAttention($now),
            'late' => $away->isPastExpected($now),
            'overdue' => $away->isLocationOverdue($now),
            'approved' => $away->approved_at !== null,
            default => true,
        };
    }

    /**
     * The list on screen: the tab's people, the ones needing attention first.
     *
     * @return Collection<int, AwayLog>
     */
    #[Computed]
    public function awayNow(): Collection
    {
        $now = CarbonImmutable::now();
        $status = array_key_exists($this->status, self::STATUSES) ? $this->status : 'all';

        return $this->openAways
            ->filter(fn (AwayLog $away) => self::hasStatus($away, $status, $now))
            ->sortBy(fn (AwayLog $away) => [self::hasStatus($away, 'attention', $now) ? 0 : 1, $away->left_at->getTimestamp()])
            ->values();
    }

    /**
     * Finished absences of the chosen day (today unless picked), for the
     * record: paged, with the real total. Earlier days are the history.
     *
     * @return LengthAwarePaginator<int, AwayLog>
     */
    #[Computed]
    public function awayToday(): LengthAwarePaginator
    {
        $day = $this->shownDay();

        // A range, not whereDate(): a function on the column would skip its index.
        return $this->filtered()->whereNotNull('returned_at')
            ->whereBetween('left_at', [$day->startOfDay(), $day->endOfDay()])
            ->with(['user.employeeProfile', 'approver'])
            ->latest('left_at')
            ->paginate(15, pageName: 'back');
    }

    #[Computed]
    public function viewingAway(): ?AwayLog
    {
        return $this->viewingAwayId !== null
            ? $this->scoped()->with(['user.employeeProfile', 'approver', 'timeLog.dutyAssignment.client'])->find($this->viewingAwayId)
            : null;
    }

    /**
     * @return array{post: array{lat: float, lng: float, radius: int, name: string}|null, points: list<array{lat: float|null, lng: float|null, accuracy: int|null, label: string, kind: string, at: CarbonImmutable, distance: int|null, mapUrl: string|null}>, address: string|null, latest: array{lat: float|null, lng: float|null, accuracy: int|null, label: string, kind: string, at: CarbonImmutable, distance: int|null, mapUrl: string|null}}|null
     */
    #[Computed]
    public function trail(): ?array
    {
        return $this->viewingAway !== null ? app(AwayTrailQuery::class)->handle($this->viewingAway) : null;
    }

    public function render(): View
    {
        return view('livewire.admin.away-monitor');
    }
}
