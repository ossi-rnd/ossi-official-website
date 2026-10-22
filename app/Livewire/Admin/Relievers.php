<?php

namespace App\Livewire\Admin;

use App\Actions\Relievers\AssignReliever;
use App\Enums\Ability;
use App\Enums\AbsenceNoticeStatus;
use App\Enums\AbsenceSource;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\AbsenceNotice;
use App\Models\User;
use App\Queries\Operations\HoldoverQuery;
use App\Queries\Operations\ReliefCandidatesQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operations' board of absence notices: who can't report (or simply didn't
 * show up: DetectNoShows), where, and who relieves them. A Satellite
 * Operation sees its region only.
 *
 * @property-read LengthAwarePaginator<int, AbsenceNotice> $notices
 * @property-read AbsenceNotice|null $assigning
 * @property-read Collection<int, User> $candidates
 * @property-read BaseCollection<int, array{name: string, label: string}> $holdover
 */
#[Title('Relievers')]
class Relievers extends Component
{
    use InteractsWithUser, WithPagination;

    #[Url(as: 'show', except: 'open')]
    public string $filter = 'open';

    public ?int $assigningId = null;

    public string $relieverId = '';

    public string $note = '';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function startAssign(int $id): void
    {
        $this->authorize(Ability::ManageRelievers->value);

        $notice = AbsenceNotice::query()->visibleTo($this->currentUser())->findOrFail($id);
        $this->assigningId = $notice->id;
        $this->relieverId = (string) $notice->reliever_id;
        $this->note = (string) $notice->cover_note;
        $this->resetErrorBag();
    }

    public function cancelAssign(): void
    {
        $this->reset(['assigningId', 'relieverId', 'note']);
    }

    public function assign(AssignReliever $assign): void
    {
        $this->authorize(Ability::ManageRelievers->value);

        $this->validate([
            'relieverId' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['relieverId.required' => 'Choose who relieves.'], ['relieverId' => 'reliever']);

        $assign->handle(
            AbsenceNotice::query()->visibleTo($this->currentUser())->findOrFail($this->assigningId),
            User::query()->findOrFail((int) $this->relieverId),
            $this->note,
            $this->currentUser(),
        );

        $this->cancelAssign();
        unset($this->notices);
        $this->dispatch('notify', message: 'Reliever assigned. Both guards have been told.');
    }

    /**
     * @return LengthAwarePaginator<int, AbsenceNotice>
     */
    #[Computed]
    public function notices(): LengthAwarePaginator
    {
        return AbsenceNotice::query()
            ->visibleTo($this->currentUser())
            ->with(['user.employeeProfile', 'reliever.employeeProfile', 'coveredBy', 'fareClaims'])
            ->when($this->filter === 'open', fn ($query) => $query->needsReliever())
            ->when($this->filter === 'covered', fn ($query) => $query->where('status', AbsenceNoticeStatus::Covered)->whereDate('duty_date', '>=', today()->toDateString()))
            // Posts that went without a guard (CloseUncoveredAbsences): what a client asks about.
            ->when($this->filter === 'not_covered', fn ($query) => $query->where('status', AbsenceNoticeStatus::NotCovered))
            // Needs reliever: today's no-shows first (that post is empty now), then by date.
            ->when($this->filter === 'open', fn ($query) => $query->orderBy('duty_date')->orderByRaw('case when source = ? then 0 else 1 end', [AbsenceSource::NoShow->value]), fn ($query) => $query->latest('duty_date'))
            ->latest('id')
            ->paginate(20);
    }

    #[Computed]
    public function assigning(): ?AbsenceNotice
    {
        return $this->assigningId !== null ? AbsenceNotice::query()->visibleTo($this->currentUser())->with(['user.employeeProfile.shift', 'client', 'dutyAssignment.shift'])->find($this->assigningId) : null;
    }

    /**
     * Who is still clocked in at that client and could stay until the reliever arrives (today only).
     *
     * @return BaseCollection<int, array{name: string, label: string}>
     */
    #[Computed]
    public function holdover(): BaseCollection
    {
        return $this->assigning !== null && $this->assigning->duty_date->isToday() ? app(HoldoverQuery::class)->handle($this->assigning) : collect();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function candidates(): Collection
    {
        return $this->assigning !== null ? app(ReliefCandidatesQuery::class)->handle($this->assigning, $this->currentUser()) : new Collection;
    }

    public function render(): View
    {
        return view('livewire.admin.relievers');
    }
}
