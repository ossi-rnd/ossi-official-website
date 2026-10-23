<?php

namespace App\Livewire\Concerns;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\Heads;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Leave / Overtime Requests split into two tabs for those who see both kinds: the President
 * or VP and the Super Admin (every office staffer's; office tab first), HR and Operation HQ
 * (the office staff they head, App\Support\Heads; guards' tab first, their main load).
 * Everyone else sees one kind (guards) and gets no tabs.
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
        return Heads::headsOfficeStaff($this->currentUser());
    }

    /** The tab shown: office staff first for the President or VP; guards first for HR and Operation HQ. */
    public function shownGroup(): string
    {
        if (! $this->hasGroups()) {
            return 'field';
        }

        return in_array($this->group, ['office', 'field'], true) ? $this->group : (Heads::seesAllOfficeStaff($this->currentUser()) ? 'office' : 'field');
    }

    /**
     * The small hint after a tab's name, for this viewer: the President or VP decide only HR staff's
     * and watch the rest; HR and Operation HQ decide the office staff they head.
     */
    public function groupHint(string $group): string
    {
        if (Heads::seesAllOfficeStaff($this->currentUser())) {
            return $group === 'office' ? 'HR staff\'s are yours to decide' : 'monitor only';
        }

        return $group === 'office' ? 'yours to decide' : '';
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
