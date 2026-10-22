<?php

namespace App\Livewire\Concerns;

use App\Enums\Region;
use App\Models\Client;
use App\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

/**
 * Region → province → client filters for a list of people, by the client
 * they're deployed to (not their home address). "Office staff" is a choice
 * of its own: they have no client. Each pick clears the ones after it, and
 * the lists only offer places that have clients (a Satellite Operation: its
 * own region only, as they see nothing else anyway).
 *
 * The page applies it with filterProfilesByArea() on an EmployeeProfile query
 * (or rowInArea() on roster rows), and uses InteractsWithUser.
 */
trait FiltersByArea
{
    /** areaRegion's value for office staff (no client). */
    public const string OFFICE = 'office';

    #[Url(as: 'region', except: '')]
    public string $areaRegion = '';

    #[Url(as: 'province', except: '')]
    public string $areaProvince = '';

    #[Url(as: 'client', except: '')]
    public string $areaClient = '';

    public function updatedAreaRegion(): void
    {
        $this->reset(['areaProvince', 'areaClient']);
        $this->backToFirstPage();
    }

    public function updatedAreaProvince(): void
    {
        $this->reset(['areaClient']);
        $this->backToFirstPage();
    }

    public function updatedAreaClient(): void
    {
        $this->backToFirstPage();
    }

    public function clearArea(): void
    {
        $this->reset(['areaRegion', 'areaProvince', 'areaClient']);
        $this->backToFirstPage();
    }

    /**
     * Whether a TodayRosterQuery row is in the chosen area: for a list filtered
     * in PHP (the dashboard's DTR) rather than in SQL.
     *
     * @param  array{office: bool, region: string|null, province: string|null, clientId: int|null}  $row
     */
    public function rowInArea(array $row): bool
    {
        return match (true) {
            $this->areaRegion === '' => true,
            $this->areaRegion === self::OFFICE => $row['office'],
            $this->areaClient !== '' => $row['clientId'] === (int) $this->areaClient,
            default => $row['region'] === $this->areaRegion && ($this->areaProvince === '' || $row['province'] === $this->areaProvince),
        };
    }

    /**
     * A Satellite Operation is offered its own region only, since the list shows no one
     * else. A page that shows every region to them (the dashboard) overrides this.
     */
    protected function limitsAreaToOwnRegion(): bool
    {
        return true;
    }

    /** Paginated lists go back to page 1; the dashboard has no pages. */
    private function backToFirstPage(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    public function hasAreaFilter(): bool
    {
        return $this->areaRegion !== '';
    }

    /**
     * @return list<Region>
     */
    public function areaRegions(): array
    {
        $own = $this->limitsAreaToOwnRegion() ? $this->currentUser()->operations_region : null;
        $used = Client::query()->distinct()->pluck('region')->map(fn (Region $region) => $region->value)->all();

        return array_values(array_filter(Region::cases(), fn (Region $region) => in_array($region->value, $used, true) && ($own === null || $own === $region)));
    }

    /**
     * @return list<string>
     */
    public function areaProvinces(): array
    {
        if (Region::tryFrom($this->areaRegion) === null) {
            return [];
        }

        return array_values(Client::query()->where('region', $this->areaRegion)->orderBy('province')->get(['province'])
            ->map(fn (Client $client): string => $client->province)
            ->filter()->unique()->all());
    }

    /**
     * @return Collection<int, Client>
     */
    public function areaClients(): Collection
    {
        if (Region::tryFrom($this->areaRegion) === null) {
            return new Collection;
        }

        return Client::query()
            ->where('region', $this->areaRegion)
            ->when($this->areaProvince !== '', fn ($query) => $query->where('province', $this->areaProvince))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Builder<EmployeeProfile>  $profiles
     * @return Builder<EmployeeProfile>
     */
    protected function filterProfilesByArea(Builder $profiles): Builder
    {
        if ($this->areaRegion === self::OFFICE) {
            return $profiles->where('is_office_staff', true);
        }

        if (Region::tryFrom($this->areaRegion) === null) {
            return $profiles;
        }

        if ($this->areaClient !== '') {
            return $profiles->where('client_id', (int) $this->areaClient);
        }

        return $profiles->whereHas('client', fn ($client) => $client
            ->where('region', $this->areaRegion)
            ->when($this->areaProvince !== '', fn ($query) => $query->where('province', $this->areaProvince)));
    }
}
