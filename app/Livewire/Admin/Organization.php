<?php

namespace App\Livewire\Admin;

use App\Enums\Ability;
use App\Enums\EmploymentStatus;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Queries\People\OrganizationQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The President or Vice President's view of the company: who holds each role at headquarters,
 * and the field side region by region down to each client's guards.
 * Read-only: people and clients are changed on Employees, Roles & Access and Clients.
 *
 * @property-read array<string, mixed> $organization
 * @property-read Client|null $openClient
 * @property-read Collection<int, EmployeeProfile> $guards
 */
#[Title('Organization')]
class Organization extends Component
{
    /** The client whose guards are listed (a modal). */
    public ?int $openClientId = null;

    /**
     * Headquarters (`chart`) and the field side (`field`), built once per render.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function organization(): array
    {
        return app(OrganizationQuery::class)->handle();
    }

    public function showClient(int $clientId): void
    {
        $this->authorize(Ability::ViewOrganization->value);
        $this->openClientId = Client::query()->findOrFail($clientId)->id;
    }

    public function closeClient(): void
    {
        $this->openClientId = null;
    }

    #[Computed]
    public function openClient(): ?Client
    {
        return $this->openClientId !== null ? Client::query()->find($this->openClientId) : null;
    }

    /**
     * The open client's active guards, by post then name.
     *
     * @return Collection<int, EmployeeProfile>
     */
    #[Computed]
    public function guards(): Collection
    {
        if ($this->openClientId === null) {
            return new Collection;
        }

        return EmployeeProfile::query()
            ->where('client_id', $this->openClientId)
            ->where('is_office_staff', false)
            ->whereIn('employment_status', EmploymentStatus::active())
            ->with('shift')
            ->orderBy('post')->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.admin.organization');
    }
}
