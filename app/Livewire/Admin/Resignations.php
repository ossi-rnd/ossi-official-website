<?php

namespace App\Livewire\Admin;

use App\Actions\Resignations\AcceptResignation;
use App\Actions\Resignations\FileResignation;
use App\Actions\Resignations\UpdateClearance;
use App\Enums\Ability;
use App\Enums\ClearanceItem;
use App\Enums\EmploymentStatus;
use App\Enums\ResignationReason;
use App\Enums\ResignationStatus;
use App\Enums\Role;
use App\Livewire\Concerns\ChoosesResignationReasons;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeDeduction;
use App\Models\EquipmentIssue;
use App\Models\Radio;
use App\Models\Resignation;
use App\Models\User;
use App\Queries\People\ResignationReasonsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Resignations, for HR (and the President or Vice President, who handles HR staff's own): accept
 * the last day, tick the clearance, and enter a paper letter for someone.
 * Each person sees only the ones they handle (Resignation::scopeHandledBy).
 *
 * @property-read LengthAwarePaginator<int, Resignation> $resignations
 * @property-read Resignation|null $opened
 * @property-read float $owed
 * @property-read list<string> $radiosHeld
 * @property-read Collection<int, User> $employees
 */
#[Title('Resignations')]
class Resignations extends Component
{
    use ChoosesResignationReasons, InteractsWithUser, WithPagination;

    #[Url(as: 'status', except: 'open')]
    public string $statusFilter = 'open';

    /** The one opened, from a list click or a notification link (?open=). */
    #[Url(as: 'open', except: null)]
    public ?int $openedId = null;

    public string $lastDay = '';

    public string $note = '';

    // "Record a resignation" for a paper letter.
    public bool $recording = false;

    public string $employeeId = '';

    public string $letter = '';

    public string $requestedLastDay = '';

    public bool $asksWaiver = false;

    /** The paper form's signed consent to deductions (Art. 113). */
    public bool $consentsToDeduction = false;

    public function mount(): void
    {
        abort_unless(Resignation::handlesAny($this->currentUser()), 403);

        if ($this->openedId !== null) {
            $this->open($this->openedId);
        }
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function open(int $id): void
    {
        // Someone else's to handle is simply not found.
        $resignation = Resignation::query()->handledBy($this->currentUser())->findOrFail($id);
        $this->openedId = $resignation->id;
        $this->lastDay = $resignation->effectiveLastDay()->toDateString();
        $this->note = (string) $resignation->decision_note;
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->reset(['openedId', 'lastDay', 'note']);
    }

    public function accept(AcceptResignation $accept): void
    {
        $validated = $this->validate(['lastDay' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:1000']], [], ['lastDay' => 'last day']);

        $accept->handle($this->openedOrFail(), $this->currentUser(), CarbonImmutable::parse($validated['lastDay']), $validated['note'] ?? null);

        unset($this->opened, $this->resignations);
        $this->dispatch('notify', message: 'Accepted. The employee was told their last day.');
    }

    public function toggle(string $item, UpdateClearance $update): void
    {
        $clearance = ClearanceItem::tryFrom($item) ?? abort(404);
        $resignation = $this->openedOrFail();

        $update->handle($resignation, $clearance, ! $resignation->isCleared($clearance), $this->currentUser());

        unset($this->opened, $this->resignations);
    }

    public function startRecording(): void
    {
        $this->reset(['employeeId', 'reasons', 'reasonOther', 'claimsJustCause', 'letter', 'asksWaiver', 'consentsToDeduction']);
        $this->requestedLastDay = CarbonImmutable::today()->addDays(Resignation::NOTICE_DAYS)->toDateString();
        $this->resetErrorBag();
        $this->recording = true;
    }

    public function cancelRecording(): void
    {
        $this->recording = false;
    }

    public function record(FileResignation $file): void
    {
        $validated = $this->validate([
            'employeeId' => ['required', Rule::in($this->employees->pluck('id')->map(fn (int $id) => (string) $id)->all())],
            ...$this->reasonRules(),
            'letter' => ['required', 'string', 'min:10', 'max:5000'],
            'requestedLastDay' => ['required', 'date'],
            'asksWaiver' => ['boolean'],
            'consentsToDeduction' => ['boolean'],
        ], ['reasons.required' => 'Tick at least one reason.'], ['employeeId' => 'employee', 'requestedLastDay' => 'last day']);

        $employee = User::query()->findOrFail((int) $validated['employeeId']);
        $resignation = $file->handle($employee, $this->chosenReasons($validated['reasons']), $validated['reasonOther'] ?? null, $validated['letter'], CarbonImmutable::parse($validated['requestedLastDay']), (bool) $validated['asksWaiver'], $this->currentUser(), (bool) $validated['consentsToDeduction']);

        $this->recording = false;
        unset($this->resignations);
        $this->open($resignation->id);
        $this->dispatch('notify', message: "Recorded {$employee->name}'s resignation. Accept it to set the last day.");
    }

    /**
     * @return LengthAwarePaginator<int, Resignation>
     */
    #[Computed]
    public function resignations(): LengthAwarePaginator
    {
        return Resignation::query()
            ->handledBy($this->currentUser())
            ->with(['user.employeeProfile'])
            ->when($this->statusFilter === 'open', fn ($query) => $query->open())
            ->when(ResignationStatus::tryFrom($this->statusFilter), fn ($query, ResignationStatus $status) => $query->where('status', $status))
            // Waiting to be accepted first, then the soonest last day.
            ->orderByRaw("case status when 'pending' then 0 when 'accepted' then 1 else 2 end")
            ->orderByRaw('coalesce(last_day, requested_last_day)')
            ->paginate(20);
    }

    #[Computed]
    public function opened(): ?Resignation
    {
        return $this->openedId !== null
            ? Resignation::query()->handledBy($this->currentUser())->with(['user.employeeProfile.client', 'decider', 'recorder', 'complaint'])->find($this->openedId)
            : null;
    }

    /**
     * Why people leave, from the reasons ticked over the last 12 months.
     *
     * @return array{filed: int, rows: list<array{reason: ResignationReason, guards: int, office: int, total: int}>, others: list<string>}
     */
    #[Computed]
    public function reasonSummary(): array
    {
        return app(ResignationReasonsQuery::class)->handle($this->currentUser());
    }

    /** What the person still owes on loans and load, for the "Loans and load settled" item. */
    #[Computed]
    public function owed(): float
    {
        $opened = $this->opened;

        return $opened === null ? 0.0 : (float) EmployeeDeduction::query()->where('user_id', $opened->user_id)->active()->sum('remaining_balance');
    }

    /**
     * Company property still with the person, for "Firearm, radio and post equipment returned":
     * radios on the NTC masterlist, and gear issued on Equipment and not returned.
     *
     * @return list<string> "Motorola XiR P3688 (SN 123ABC)", "Flashlight"
     */
    #[Computed]
    public function radiosHeld(): array
    {
        $opened = $this->opened;

        if ($opened === null) {
            return [];
        }

        $radios = Radio::query()->where('assigned_user_id', $opened->user_id)->orderBy('serial_number')->get()->all();
        $gear = EquipmentIssue::query()->outstanding()->where('user_id', $opened->user_id)->whereNull('radio_id')->orderBy('id')->get()->all();

        return [
            ...array_map(fn (Radio $radio) => "{$radio->brand} {$radio->model} (SN {$radio->serial_number})", $radios),
            ...array_map(fn (EquipmentIssue $issue) => $issue->describe(), $gear),
        ];
    }

    /**
     * Employees this person may record a resignation for: still working here,
     * none in progress, and theirs to handle (an HR member's is the President or Vice President's).
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function employees(): Collection
    {
        $user = $this->currentUser();
        // HR: everyone but HR staff. The President or Vice President: HR staff only. Super Admin: both.
        $onlyHrStaff = ! $user->can(Ability::HandleResignations->value);
        $exceptHrStaff = ! $user->can(Ability::HandleHrResignations->value);

        return User::query()
            ->whereKeyNot($user->id)
            ->whereHas('employeeProfile', fn ($profile) => $profile->whereIn('employment_status', EmploymentStatus::active()))
            ->whereDoesntHave('resignations', fn ($resignation) => $resignation->open())
            ->whereDoesntHave('roles', fn ($roles) => $roles->whereIn('role', array_map(fn (Role $role) => $role->value, Role::paidOutsidePayroll())))
            ->when($exceptHrStaff, fn ($query) => $query->whereDoesntHave('roles', fn ($roles) => $roles->where('role', Role::Hr->value)))
            ->when($onlyHrStaff, fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->where('role', Role::Hr->value)))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function render(): View
    {
        return view('livewire.admin.resignations', ['statuses' => ResignationStatus::cases()]);
    }

    private function openedOrFail(): Resignation
    {
        return $this->opened ?? abort(404);
    }
}
