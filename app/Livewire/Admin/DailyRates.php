<?php

namespace App\Livewire\Admin;

use App\Actions\Payroll\SetPositionRate;
use App\Actions\People\SetEmployeeRate;
use App\Data\ResolvedRate;
use App\Enums\Ability;
use App\Enums\Position;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRate;
use App\Models\PositionRate;
use App\Queries\People\EffectiveRatesQuery;
use App\Queries\People\PositionRatesInForceQuery;
use App\Services\Payroll\RateScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Accounting sets pay here (HR only sees it on the employee record):
 *
 *  - Rate per position: the company's daily rate for each position, from a date;
 *  - Employees: each person's rate today and where it comes from, and a
 *    personal rate that overrides their position's.
 *
 * Every change is a new dated row, so payroll periods use the rate in force
 * when they start, and the history stays readable.
 *
 * @property-read LengthAwarePaginator<int, EmployeeProfile> $profiles
 * @property-read array<int, ResolvedRate> $resolved
 * @property-read array<int, EmployeeRate> $upcoming
 * @property-read int $missingCount
 * @property-read EmployeeProfile|null $editingProfile
 * @property-read Collection<int, EmployeeRate> $history
 * @property-read list<array{position: Position, rate: float|null, next: PositionRate|null, employees: int}> $positionRows
 * @property-read Collection<int, PositionRate> $positionHistory
 * @property-read float $fieldDefault
 * @property-read float $officeDefault
 */
#[Title('Daily Rates')]
class DailyRates extends Component
{
    use InteractsWithUser, WithPagination;

    #[Url(except: 'employees')]
    public string $tab = 'employees';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'show', except: 'all')]
    public string $filter = 'all';

    /** The employee whose personal rate is being changed. */
    public ?int $editingId = null;

    /** The position whose company rate is being changed. */
    public ?string $editingPosition = null;

    public string $rateAmount = '';

    public string $rateEffectiveFrom = '';

    public string $rateNote = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    // ---- Employees -------------------------------------------------------

    /**
     * @return LengthAwarePaginator<int, EmployeeProfile>
     */
    #[Computed]
    public function profiles(): LengthAwarePaginator
    {
        return EmployeeProfile::query()->paidHere()
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('employee_number', 'like', "%{$this->search}%")))
            ->when($this->filter === 'missing', fn (Builder $query) => $query->withoutRate())
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * Today's rate, and where it comes from, for the employees on this page.
     *
     * @return array<int, ResolvedRate>
     */
    #[Computed]
    public function resolved(): array
    {
        return app(EffectiveRatesQuery::class)->handle($this->profiles->items(), CarbonImmutable::today());
    }

    /**
     * The next scheduled personal rate for the employees on this page.
     *
     * @return array<int, EmployeeRate>
     */
    #[Computed]
    public function upcoming(): array
    {
        $upcoming = [];

        EmployeeRate::query()
            ->whereIn('employee_profile_id', array_map(fn (EmployeeProfile $profile) => $profile->id, $this->profiles->items()))
            ->whereDate('effective_from', '>', today()->toDateString())
            ->orderByDesc('effective_from')
            ->get()
            ->each(function (EmployeeRate $rate) use (&$upcoming) {
                $upcoming[$rate->employee_profile_id] = $rate;   // descending, so the earliest wins
            });

        return $upcoming;
    }

    /**
     * Active employees with neither a personal nor a position rate.
     */
    #[Computed]
    public function missingCount(): int
    {
        return EmployeeProfile::query()->paidHere()->withoutRate()->count();
    }

    #[Computed]
    public function editingProfile(): ?EmployeeProfile
    {
        return $this->editingId !== null ? EmployeeProfile::query()->find($this->editingId) : null;
    }

    /**
     * @return Collection<int, EmployeeRate>
     */
    #[Computed]
    public function history(): Collection
    {
        return $this->editingId === null
            ? new Collection
            : EmployeeRate::query()->where('employee_profile_id', $this->editingId)->with('setter:id,name')->orderByDesc('effective_from')->get();
    }

    public function startRateChange(int $profileId): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $profile = EmployeeProfile::query()->findOrFail($profileId);
        $current = app(EffectiveRatesQuery::class)->forProfile($profile, CarbonImmutable::today());

        $this->openForm(rate: $current->rate);
        $this->editingId = $profileId;
    }

    public function saveRate(SetEmployeeRate $setRate): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $profile = $this->editingProfile ?? abort(404);
        [$amount, $from, $note] = $this->validatedForm();

        $rate = $setRate->handle($profile, $amount, $from, $note, $this->currentUser());

        $this->closeForm();
        $this->dispatch('notify', message: "{$profile->name}: ₱".number_format((float) $rate->daily_rate, 2)." a day from {$rate->effective_from->format('M j, Y')}.");
    }

    // ---- Rate per position ---------------------------------------------

    /**
     * Every position with its company rate today, the next change, and how
     * many active employees are paid it (those without a personal rate).
     *
     * @return list<array{position: Position, rate: float|null, next: PositionRate|null, employees: int}>
     */
    #[Computed]
    public function positionRows(): array
    {
        $today = today()->toDateString();
        $inForce = app(PositionRatesInForceQuery::class)->handle(CarbonImmutable::today());

        $next = [];
        PositionRate::query()->whereDate('effective_from', '>', $today)->orderByDesc('effective_from')->get()
            ->each(function (PositionRate $rate) use (&$next) {
                $next[$rate->position->value] = $rate;
            });

        $counts = EmployeeProfile::query()->paidHere()
            ->whereNotNull('position')
            ->whereDoesntHave('rates', fn (Builder $rates) => $rates->whereDate('effective_from', '<=', $today))
            ->toBase()
            ->pluck('position')
            ->countBy();

        // The positions given today; a retired one (Security Manager…) only while someone still holds it.
        $positions = array_values(array_filter(Position::cases(), fn (Position $position) => in_array($position, Position::offered(), true)
            || EmployeeProfile::query()->paidHere()->where('position', $position->value)->exists()));

        return array_map(fn (Position $position) => [
            'position' => $position,
            'rate' => $inForce[$position->value] ?? null,
            'next' => $next[$position->value] ?? null,
            'employees' => (int) ($counts[$position->value] ?? 0),
        ], $positions);
    }

    /**
     * @return Collection<int, PositionRate>
     */
    #[Computed]
    public function positionHistory(): Collection
    {
        return $this->editingPosition === null
            ? new Collection
            : PositionRate::query()->where('position', $this->editingPosition)->with('setter:id,name')->orderByDesc('effective_from')->get();
    }

    public function startPositionChange(string $position): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $position = Position::from($position);
        $current = app(PositionRatesInForceQuery::class)->handle(CarbonImmutable::today())[$position->value] ?? null;

        $this->openForm(rate: $current);
        $this->editingPosition = $position->value;
    }

    public function savePositionRate(SetPositionRate $setRate): void
    {
        $this->authorize(Ability::ManagePayroll->value);

        $position = Position::from((string) $this->editingPosition);
        [$amount, $from, $note] = $this->validatedForm();

        $rate = $setRate->handle($position, $amount, $from, $note, $this->currentUser());

        $this->closeForm();
        $this->dispatch('notify', message: "{$position->label()}: ₱".number_format((float) $rate->daily_rate, 2)." a day from {$rate->effective_from->format('M j, Y')}.");
    }

    // ---- Shared -------------------------------------------------------

    #[Computed]
    public function fieldDefault(): float
    {
        return app(RateScheduleResolver::class)->forDate(CarbonImmutable::today())->dailyRate(false);
    }

    #[Computed]
    public function officeDefault(): float
    {
        return app(RateScheduleResolver::class)->forDate(CarbonImmutable::today())->dailyRate(true);
    }

    public function closeForm(): void
    {
        $this->editingId = null;
        $this->editingPosition = null;
        unset($this->profiles, $this->resolved, $this->upcoming, $this->missingCount, $this->editingProfile, $this->history, $this->positionRows, $this->positionHistory);
    }

    public function render(): View
    {
        return view('livewire.admin.daily-rates');
    }

    private function openForm(?float $rate): void
    {
        $this->closeForm();
        $this->rateAmount = $rate !== null ? (string) $rate : '';
        $this->rateEffectiveFrom = today()->toDateString();
        $this->rateNote = '';
        $this->resetErrorBag();
    }

    /**
     * @return array{0: float, 1: CarbonImmutable, 2: string|null}
     */
    private function validatedForm(): array
    {
        $validated = $this->validate([
            'rateAmount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'rateEffectiveFrom' => ['required', 'date'],
            'rateNote' => ['nullable', 'string', 'max:255'],
        ], attributes: ['rateAmount' => 'daily rate', 'rateEffectiveFrom' => 'effective date']);

        return [(float) $validated['rateAmount'], CarbonImmutable::parse($validated['rateEffectiveFrom']), $validated['rateNote'] ?: null];
    }
}
