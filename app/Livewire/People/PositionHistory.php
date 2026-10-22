<?php

namespace App\Livewire\People;

use App\Actions\People\CancelPositionChange;
use App\Actions\People\ChangePosition;
use App\Enums\Ability;
use App\Enums\Position;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeProfile;
use App\Models\PositionChange;
use App\Queries\People\PositionRatesInForceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Position history on the 201 file (roadmap 22): every promotion and change of
 * position with its date and reason. HR promotes or changes the position here;
 * the date is a cut-off start, and the form shows the rate it brings.
 *
 * @property-read EmployeeProfile $profile
 * @property-read Collection<int, PositionChange> $changes
 */
class PositionHistory extends Component
{
    use InteractsWithUser;

    #[Locked]
    public int $profileId;

    public bool $changing = false;

    public string $kind = PositionChange::PROMOTION;

    public string $toPosition = '';

    public string $effectiveOn = '';

    public string $reason = '';

    public function mount(int $profileId): void
    {
        $this->authorize(Ability::ViewPeople->value);
        $this->profileId = $profileId;
    }

    #[Computed]
    public function profile(): EmployeeProfile
    {
        return EmployeeProfile::query()->findOrFail($this->profileId);
    }

    /**
     * @return Collection<int, PositionChange>
     */
    #[Computed]
    public function changes(): Collection
    {
        return PositionChange::query()->where('employee_profile_id', $this->profileId)->with('recorder:id,name')->orderByDesc('effective_on')->orderByDesc('id')->get();
    }

    /**
     * The next cut-off start: the 16th, or the 1st of next month.
     */
    public static function nextCutoffStart(CarbonImmutable $today): CarbonImmutable
    {
        return $today->day < 16 ? $today->setDay(16) : $today->addMonthNoOverflow()->startOfMonth();
    }

    /**
     * What the chosen position pays from the chosen date, for the form.
     */
    public function rateThen(): ?float
    {
        $position = Position::tryFrom($this->toPosition);

        if ($position === null || $this->effectiveOn === '') {
            return null;
        }

        return app(PositionRatesInForceQuery::class)->handle(CarbonImmutable::parse($this->effectiveOn))[$position->value] ?? null;
    }

    public function start(): void
    {
        $this->authorize(Ability::ManagePeople->value);
        $this->reset(['toPosition', 'reason']);
        $this->kind = PositionChange::PROMOTION;
        $this->effectiveOn = self::nextCutoffStart(CarbonImmutable::today())->toDateString();
        $this->changing = true;
        $this->resetErrorBag();
    }

    public function save(ChangePosition $change): void
    {
        $this->validate([
            'kind' => ['required', Rule::in([PositionChange::PROMOTION, PositionChange::TRANSFER])],
            'toPosition' => ['required', Rule::enum(Position::class)],
            'effectiveOn' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ], ['toPosition.required' => 'Choose the new position.', 'reason.required' => 'Say why.']);

        $recorded = $change->handle($this->currentUser(), $this->profile, Position::from($this->toPosition), $this->kind, CarbonImmutable::parse($this->effectiveOn), $this->reason);

        $this->changing = false;
        unset($this->changes, $this->profile);
        $this->dispatch('notify', message: $recorded->applied_at !== null
            ? "Now {$recorded->to_position->label()}. Pay uses it from the {$recorded->effective_on->format('M j')} cut-off; the employee and Accounting are told."
            : "{$recorded->to_position->label()} from {$recorded->effective_on->format('M j')}. The employee and Accounting are told.");
    }

    public function cancel(int $id, CancelPositionChange $cancel): void
    {
        $cancel->handle($this->currentUser(), PositionChange::query()->where('employee_profile_id', $this->profileId)->findOrFail($id));
        unset($this->changes);
        $this->dispatch('notify', message: 'Cancelled.');
    }

    public function render(): View
    {
        return view('livewire.people.position-history', [
            'positions' => Position::for((bool) $this->profile->is_office_staff),
            'canManage' => $this->currentUser()->can(Ability::ManagePeople->value),
        ]);
    }
}
