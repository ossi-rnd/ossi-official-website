<?php

namespace App\Livewire\Admin;

use App\Actions\People\GiveMerit;
use App\Actions\People\SaveMeritType;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeProfile;
use App\Models\MeritRecord;
use App\Models\MeritType;
use App\Models\User;
use App\Queries\People\PeopleToActOnQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Merits & Demerits (roadmap 21): the ones given to people this viewer
 * supervises, giving a new one (from HR's list of types, or "Other"), and,
 * for HR, that list of types. A person's own history is on their 201 file
 * and on My Profile (People\MeritHistory).
 *
 * @property-read LengthAwarePaginator<int, MeritRecord> $records
 * @property-read Collection<int, MeritType> $types
 * @property-read Collection<int, User> $candidates
 * @property-read User|null $chosen
 */
#[Title('Merits & Demerits')]
class Merits extends Component
{
    use InteractsWithUser, WithPagination;

    /** all | merit | demerit */
    #[Url(as: 'show')]
    public string $kindFilter = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    // Giving one. ?give=user_id opens the form for that person (from their 201 file).
    #[Url(as: 'give')]
    public ?int $giveTo = null;

    public bool $giving = false;

    public string $personSearch = '';

    /** a type id, or "other" */
    public string $typeId = '';

    public string $otherTitle = '';

    /** merit | demerit, for "other" */
    public string $otherKind = MeritType::MERIT;

    public string $otherPoints = '';

    public string $occurredOn = '';

    public string $reason = '';

    // HR's list of types.
    public ?int $editingTypeId = null;

    public bool $addingType = false;

    public string $typeName = '';

    public string $typeKind = MeritType::DEMERIT;

    public string $typePoints = '';

    public string $typeDescription = '';

    public function mount(): void
    {
        $this->authorize(Ability::GiveMerits->value);

        if ($this->giveTo !== null) {
            $this->startGiving($this->giveTo);
        }
    }

    public function updatedKindFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, MeritRecord>
     */
    #[Computed]
    public function records(): LengthAwarePaginator
    {
        return MeritRecord::query()
            ->visibleTo($this->currentUser())
            ->when($this->kindFilter !== 'all', fn ($query) => $query->where('kind', $this->kindFilter))
            ->when(trim($this->search) !== '', fn ($query) => $query->whereHas('user', fn ($user) => $user->where('name', 'like', '%'.trim($this->search).'%')))
            ->with(['user.employeeProfile.client', 'giver:id,name'])
            ->latest('occurred_on')
            ->latest('id')
            ->paginate(20);
    }

    /**
     * @return Collection<int, MeritType>
     */
    #[Computed]
    public function types(): Collection
    {
        return MeritType::query()->orderBy('kind')->orderBy('points')->orderBy('name')->get();
    }

    /**
     * Up to 8 people this viewer may give one to, matching the name typed.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function candidates(): Collection
    {
        $me = $this->currentUser();

        return app(PeopleToActOnQuery::class)->handle($me, $this->personSearch, fn (User $user) => MeritRecord::canGive($me, $user), $this->lastClientId());
    }

    /**
     * Before typing, suggest the client site of the last one this viewer gave.
     */
    public function lastClientId(): ?int
    {
        $lastUserId = MeritRecord::query()->where('given_by', $this->currentUser()->id)->latest('id')->value('user_id');

        return $lastUserId !== null ? EmployeeProfile::query()->where('user_id', $lastUserId)->value('client_id') : null;
    }

    #[Computed]
    public function chosen(): ?User
    {
        return $this->giveTo !== null ? User::query()->with('employeeProfile.client')->find($this->giveTo) : null;
    }

    public function startGiving(?int $userId = null): void
    {
        $this->authorize(Ability::GiveMerits->value);
        $this->reset(['personSearch', 'typeId', 'otherTitle', 'otherPoints', 'reason']);
        $this->otherKind = MeritType::MERIT;
        $this->occurredOn = CarbonImmutable::today()->toDateString();
        $this->giveTo = $userId !== null && ($user = User::query()->find($userId)) !== null && MeritRecord::canGive($this->currentUser(), $user) ? $user->id : null;
        unset($this->chosen);
        $this->giving = true;
        $this->resetErrorBag();
    }

    public function choose(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        abort_unless(MeritRecord::canGive($this->currentUser(), $user), 403);

        $this->giveTo = $user->id;
        $this->personSearch = '';
        unset($this->chosen);
    }

    public function closeGiving(): void
    {
        $this->reset(['giving', 'giveTo', 'personSearch', 'typeId', 'otherTitle', 'otherPoints', 'reason']);
    }

    public function give(GiveMerit $give): void
    {
        $this->authorize(Ability::GiveMerits->value);

        $this->validate([
            'giveTo' => ['required', 'integer'],
            'typeId' => ['required'],
            'occurredOn' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'giveTo.required' => 'Choose the employee.',
            'typeId.required' => 'Choose what it\'s for.',
            'reason.required' => 'Say what happened.',
        ]);

        $other = $this->typeId === 'other';
        $points = (int) $this->otherPoints;

        $record = $give->handle(
            $this->currentUser(),
            User::query()->findOrFail($this->giveTo),
            $other ? null : MeritType::query()->findOrFail((int) $this->typeId),
            $this->reason,
            CarbonImmutable::parse($this->occurredOn),
            $other ? $this->otherTitle : null,
            $other ? ($this->otherKind === MeritType::DEMERIT ? -abs($points) : abs($points)) : null,
        );

        $this->closeGiving();
        unset($this->records);
        $this->dispatch('notify', message: ucfirst($record->kind).' given to '.$record->user->name.'. They\'re told by bell and email.');
    }

    // ---- HR's list of types ------------------------------------------------

    public function startType(?int $id = null): void
    {
        $this->authorize(Ability::ManageMeritTypes->value);
        $type = $id !== null ? MeritType::query()->findOrFail($id) : null;

        $this->editingTypeId = $type?->id;
        $this->addingType = $type === null;
        $this->typeName = $type->name ?? '';
        $this->typeKind = $type->kind ?? MeritType::DEMERIT;
        $this->typePoints = $type !== null ? (string) $type->points : '';
        $this->typeDescription = $type->description ?? '';
        $this->resetErrorBag();
    }

    public function cancelType(): void
    {
        $this->reset(['editingTypeId', 'addingType', 'typeName', 'typePoints', 'typeDescription']);
    }

    public function saveType(SaveMeritType $save): void
    {
        $type = $this->editingTypeId !== null ? MeritType::query()->findOrFail($this->editingTypeId) : null;
        $save->handle($this->currentUser(), $type, $this->typeName, $this->typeKind, (int) $this->typePoints, $this->typeDescription, $type->is_active ?? true);

        $this->cancelType();
        unset($this->types);
        $this->dispatch('notify', message: 'Saved.');
    }

    public function toggleType(int $id, SaveMeritType $save): void
    {
        $type = MeritType::query()->findOrFail($id);
        $save->handle($this->currentUser(), $type, $type->name, $type->kind, $type->points, $type->description, ! $type->is_active);

        unset($this->types);
        $this->dispatch('notify', message: $type->is_active ? 'Back in use.' : 'Switched off. Past records keep it.');
    }

    public function render(): View
    {
        return view('livewire.admin.merits');
    }
}
