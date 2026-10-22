<?php

namespace App\Livewire\Concerns;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Leave / Overtime Requests split into two tabs for those who see both
 * kinds (the President or Vice President, the Super Admin): "Office staff", theirs to decide,
 * first; "Guards", which they only monitor. Everyone else sees one kind
 * (guards) and gets no tabs.
 *
 * The component says which request model it lists in requestModel().
 */
trait GroupsByStaffKind
{
    /** 'office' | 'field'; '' = the default for this viewer. */
    #[Url(as: 'group', except: '')]
    public string $group = '';

    /**
     * @return class-string<LeaveRequest|OvertimeRequest>
     */
    abstract protected function requestModel(): string;

    abstract protected function currentUser(): User;

    /** Sees both kinds, so gets the tabs. */
    public function hasGroups(): bool
    {
        $model = $this->requestModel();

        return $this->currentUser()->can($model::reviewAbilities()['office']->value);
    }

    /** The tab shown: office staff first for those who decide them. */
    public function shownGroup(): string
    {
        if (! $this->hasGroups()) {
            return 'field';
        }

        return in_array($this->group, ['office', 'field'], true) ? $this->group : 'office';
    }

    public function updatedGroup(): void
    {
        $this->resetPage();
    }

    /**
     * A tab was clicked. A method rather than $set, so the list can show it's loading
     * (wire:target="showGroup") while the browser has already moved the underline.
     */
    public function showGroup(string $group): void
    {
        $this->group = in_array($group, ['office', 'field'], true) ? $group : '';
        $this->updatedGroup();
    }

    /**
     * Narrows a request query to the tab shown.
     *
     * @template TModel of LeaveRequest|OvertimeRequest
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function inShownGroup(Builder $query): Builder
    {
        return $query->whereHas('user', fn (Builder $user) => $this->shownGroup() === 'office' ? $user->officeStaff() : $user->fieldStaff());
    }

    /**
     * Pending requests in each tab, for the tab labels.
     *
     * @return array{office: int, field: int}
     */
    #[Computed]
    public function groupCounts(): array
    {
        $model = $this->requestModel();
        $pending = fn (string $kind) => $model::query()->pending()->visibleTo($this->currentUser())
            ->whereHas('user', fn (Builder $user) => $kind === 'office' ? $user->officeStaff() : $user->fieldStaff())->count();

        return ['office' => $pending('office'), 'field' => $pending('field')];
    }
}
