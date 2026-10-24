<?php

namespace App\Livewire\Admin;

use App\Actions\People\AddEmployee;
use App\Actions\People\SaveProbationRules;
use App\Actions\People\ShiftForTimes;
use App\Enums\Ability;
use App\Enums\EmploymentStatus;
use App\Enums\RequirementStatus;
use App\Enums\Role;
use App\Livewire\Concerns\FiltersByArea;
use App\Livewire\Concerns\InteractsWithUser;
use App\Livewire\Forms\EmployeeProfileForm;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Queries\People\MeritTotalsQuery;
use App\Support\ProbationRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * HR's roster. Adding an employee here is what allows them to register:
 * share the employee number, and they sign up with the email entered here.
 *
 * @property-read LengthAwarePaginator<int, EmployeeProfile> $employees
 */
#[Title('Employees')]
class Employees extends Component
{
    use FiltersByArea, InteractsWithUser, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    #[Url(as: 'account', except: 'all')]
    public string $accountFilter = 'all';

    /** all | field | office: one search box for everyone, narrowed by kind. */
    #[Url(as: 'kind', except: 'all')]
    public string $kind = 'all';

    /**
     * Sees office staff too (view-office-staff: HR, the President or Vice President, Admin, Accounting):
     * gets the All · Guards · Office staff tabs. Operations sees guards only, no tabs.
     */
    public function seesBothKinds(): bool
    {
        return $this->currentUser()->can(Ability::ViewOfficeStaff->value);
    }

    public function showKind(string $kind): void
    {
        $this->kind = in_array($kind, ['field', 'office'], true) ? $kind : 'all';
        $this->resetPage();
    }

    /**
     * How many of each kind this viewer sees (for the tabs), with the search and filters applied.
     *
     * @return array{all: int, field: int, office: int}
     */
    #[Computed]
    public function kindCounts(): array
    {
        $count = fn (?bool $office) => $this->filtered()->when($office !== null, fn ($query) => $query->where('is_office_staff', $office))->count();

        return ['all' => $count(null), 'field' => $count(false), 'office' => $count(true)];
    }

    // Probation & training rules: HR sets them (SaveProbationRules, audited).
    public bool $editingRules = false;

    public string $probationMonths = '';

    public string $remindDaysBefore = '';

    public string $trainingDays = '';

    public function canEditRules(): bool
    {
        return $this->currentUser()->can(Ability::ManagePeople->value);
    }

    public function editRules(): void
    {
        abort_unless($this->canEditRules(), 403);

        $this->probationMonths = (string) ProbationRules::months();
        $this->remindDaysBefore = (string) ProbationRules::remindDaysBefore();
        $this->trainingDays = (string) ProbationRules::trainingDays();
        $this->resetErrorBag();
        $this->editingRules = true;
    }

    public function saveRules(SaveProbationRules $save): void
    {
        $validated = $this->validate([
            'probationMonths' => ['required', 'integer', 'min:1', 'max:'.ProbationRules::MAX_MONTHS],
            'remindDaysBefore' => ['required', 'integer', 'min:7', 'max:60'],
            'trainingDays' => ['required', 'integer', 'min:1', 'max:180'],
        ], ['probationMonths.max' => 'The law allows no more than 6 months.'], ['probationMonths' => 'probation length', 'remindDaysBefore' => 'days before', 'trainingDays' => 'training length']);

        $save->handle($this->currentUser(), (int) $validated['probationMonths'], (int) $validated['remindDaysBefore'], (int) $validated['trainingDays']);

        $this->editingRules = false;
        $this->dispatch('notify', message: 'Probation and training rules saved.');
    }

    public bool $showForm = false;

    public EmployeeProfileForm $form;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAccountFilter(): void
    {
        $this->resetPage();
    }

    /** Region → province → detachment stay consistent as HR picks. */
    public function updatedForm(mixed $value, string $key): void
    {
        $this->form->areaChanged($key);
        $this->form->numberChanged($key);
    }

    /** Generated number, or the one on an ID card already printed. */
    public function useNumberFrom(string $source): void
    {
        $this->form->useNumberFrom($source);
        $this->resetErrorBag('form.employee_number');
    }

    public function create(): void
    {
        $this->authorize(Ability::ManagePeople->value);

        $this->form->reset();
        // The company's default hours, for HR to change.
        $this->form->useShiftTimes(Shift::query()->where('is_default', true)->first());
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(AddEmployee $addEmployee, ShiftForTimes $shiftForTimes): void
    {
        // Re-checked on every write, not just on page load (spec).
        $this->authorize(Ability::ManagePeople->value);

        $attributes = $this->form->toProfileAttributes();
        $profile = $addEmployee->handle($attributes, $this->form->chosenShift($shiftForTimes, $this->currentUser()), $this->currentUser());

        $this->showForm = false;
        $this->dispatch('notify', message: "Added {$profile->name}. Give them employee number {$profile->employee_number} so they can register.");
    }

    /**
     * @return LengthAwarePaginator<int, EmployeeProfile>
     */
    #[Computed]
    public function employees(): LengthAwarePaginator
    {
        return $this->filtered()
            ->with(['user', 'shift'])
            // 201 completion in the same query: on file and not expired.
            ->withCount(['requirements as requirements_on_file_count' => fn ($query) => $query
                ->where('status', RequirementStatus::OnFile)
                ->where(fn ($valid) => $valid->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()->toDateString()))])
            ->when($this->seesBothKinds() && $this->kind === 'field', fn ($query) => $query->where('is_office_staff', false))
            ->when($this->seesBothKinds() && $this->kind === 'office', fn ($query) => $query->where('is_office_staff', true))
            ->orderBy('name')
            ->paginate(20);
    }

    /**
     * Who this viewer sees, with the search and filters (not the kind tab):
     * shared by the list and the tab counts.
     *
     * @return Builder<EmployeeProfile>
     */
    private function filtered(): Builder
    {
        return $this->filterProfilesByArea(EmployeeProfile::query())
            ->visibleTo($this->currentUser())
            // Operations doesn't see office staff (view-office-staff), here as on the dashboard.
            ->when(! $this->seesBothKinds(), fn ($query) => $query->where('is_office_staff', false))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
                ->orWhere('employee_number', 'like', "%{$this->search}%")))
            ->when(EmploymentStatus::tryFrom($this->statusFilter), fn ($query, EmploymentStatus $status) => $query->where('employment_status', $status))
            ->when($this->accountFilter === 'registered', fn ($query) => $query->whereNotNull('user_id'))
            ->when($this->accountFilter === 'unregistered', fn ($query) => $query->whereNull('user_id'));
    }

    /**
     * 12-month merit and demerit points for the people on this page, one query,
     * for those who may see merits (give them, or HR / the President or Vice President).
     *
     * @return array<int, array{merits: int, demerits: int, net: int}>
     */
    #[Computed]
    public function meritTotals(): array
    {
        $viewer = $this->currentUser();

        if (! $viewer->can(Ability::GiveMerits->value)) {
            return [];
        }

        // HR, the President or Vice President and Super Admin see everyone's; Operations, guards' only.
        $everyone = $viewer->hasAnyRole(Role::Hr, Role::SuperAdmin, ...Role::leadership());
        $ids = [];
        foreach ($this->employees->items() as $profile) {
            if ($profile->user_id !== null && ($everyone || ! $profile->is_office_staff)) {
                $ids[] = $profile->user_id;
            }
        }

        return app(MeritTotalsQuery::class)->handle($ids);
    }

    public function render(): View
    {
        return view('livewire.admin.employees');
    }
}
