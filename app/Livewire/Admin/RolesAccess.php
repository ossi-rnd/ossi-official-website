<?php

namespace App\Livewire\Admin;

use App\Actions\Roles\SetPendingRole;
use App\Actions\Roles\SyncUserRoles;
use App\Enums\Ability;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read Collection<int, EmployeeProfile> $waiting
 */
#[Title('Roles & Access')]
class RolesAccess extends Component
{
    use InteractsWithUser, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $editingUserId = null;

    /** Someone HR added who hasn't registered: their role is set in advance (SetPendingRole). */
    public ?int $editingProfileId = null;

    /**
     * One role per person (2026-10-21): the job role picked, or '' for a regular employee.
     * "Employee" isn't picked: anyone with an employee record keeps it automatically.
     * What each role can open is unchanged (Ability::roles()).
     */
    public string $jobRole = '';

    /**
     * Roles the account held before one-role-per-person, when more than one (labels).
     * Shown as a warning: saving keeps only the one picked.
     *
     * @var list<string>
     */
    public array $previousRoles = [];

    /** The region, when Satellite Operation is picked. */
    public string $region = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function edit(int $userId): void
    {
        $this->authorize(Ability::ConfigureSystem->value);

        $user = User::query()->with('roles')->findOrFail($userId);
        $this->editingUserId = $user->id;
        $jobRoles = array_values(array_filter($user->roleList(), fn (Role $role) => $role !== Role::Employee));
        $this->jobRole = (string) ($jobRoles[0] ?? null)?->value;
        $this->previousRoles = count($jobRoles) > 1 ? array_map(fn (Role $role) => $role->label(), $jobRoles) : [];
        $this->region = (string) $user->operations_region?->value;
        $this->resetErrorBag();
    }

    public function editPending(int $profileId): void
    {
        $this->authorize(Ability::ConfigureSystem->value);

        $profile = EmployeeProfile::query()->whereNull('user_id')->findOrFail($profileId);
        $this->reset(['editingUserId', 'previousRoles']);
        $this->editingProfileId = $profile->id;
        $this->jobRole = (string) $profile->pending_role?->value;
        $this->region = (string) $profile->pending_region?->value;
        $this->resetErrorBag();
    }

    public function savePending(SetPendingRole $setPendingRole): void
    {
        $this->authorize(Ability::ConfigureSystem->value);

        $this->validate([
            'jobRole' => ['nullable', 'in:'.implode(',', array_map(fn (Role $role) => $role->value, self::recordRoles()))],
            'region' => ['nullable', 'string'],
        ]);

        $setPendingRole->handle(EmployeeProfile::query()->findOrFail((int) $this->editingProfileId), Role::tryFrom($this->jobRole), Region::tryFrom($this->region), $this->currentUser());

        $this->cancel();
        unset($this->waiting);
        $this->dispatch('notify', message: 'Role saved. They get it when they register.');
    }

    /**
     * Roles for someone with an employee record: not Employee (automatic), and not Super Admin,
     * President or Vice President (accounts outside payroll, without a record).
     *
     * @return list<Role>
     */
    public static function recordRoles(): array
    {
        return array_values(array_filter(Role::cases(), fn (Role $role) => $role !== Role::Employee && ! in_array($role, Role::withoutEmployeeRecord(), true)));
    }

    public function cancel(): void
    {
        $this->reset(['editingUserId', 'editingProfileId', 'jobRole', 'previousRoles', 'region']);
    }

    public function save(SyncUserRoles $syncRoles): void
    {
        $this->authorize(Ability::ConfigureSystem->value);

        $jobRoles = array_filter(Role::cases(), fn (Role $role) => $role !== Role::Employee);
        $this->validate([
            'jobRole' => ['nullable', 'in:'.implode(',', array_map(fn (Role $role) => $role->value, $jobRoles))],
            'region' => ['nullable', 'string'],
        ]);

        $target = User::query()->with('roles')->findOrFail($this->editingUserId);

        // The one job role, plus Employee for anyone with an employee record (or who had it).
        $roles = array_values(array_filter([
            Role::tryFrom($this->jobRole),
            $target->hasRole(Role::Employee) || $target->employeeProfile()->exists() ? Role::Employee : null,
        ]));

        $syncRoles->handle($target, $roles, $this->currentUser(), Region::tryFrom($this->region));

        $this->cancel();
        unset($this->users);
        $this->dispatch('notify', message: 'Roles updated. The change applies on their next click.');
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles', 'employeeProfile'])
            ->when($this->search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * Added by HR, not registered yet (still working here): office staff first, then by name.
     *
     * @return Collection<int, EmployeeProfile>
     */
    #[Computed]
    public function waiting(): Collection
    {
        return EmployeeProfile::query()->active()->whereNull('user_id')
            ->when($this->search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")->orWhere('employee_number', 'like', "%{$this->search}%")))
            ->orderByDesc('is_office_staff')->orderBy('name')
            ->get();
    }

    #[Computed]
    public function editingProfile(): ?EmployeeProfile
    {
        return $this->editingProfileId !== null ? EmployeeProfile::query()->find($this->editingProfileId) : null;
    }

    #[Computed]
    public function editingUser(): ?User
    {
        return $this->editingUserId !== null ? User::query()->find($this->editingUserId) : null;
    }

    public function render(): View
    {
        return view('livewire.admin.roles-access', [
            'roles' => Role::cases(),
            'abilities' => Ability::cases(),
        ]);
    }
}
