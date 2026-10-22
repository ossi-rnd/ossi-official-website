<?php

namespace App\Livewire\Admin;

use App\Actions\Settings\DeleteClient;
use App\Actions\Settings\SaveClient;
use App\Enums\Ability;
use App\Enums\Region;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Queries\Attendance\ClientAttendanceTodayQuery;
use App\Support\PhilippineFormats;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Super Admin's list of client sites, one tab per region, grouped by
 * province: Region IV-A → Cavite → Metrobank. HR then picks one of these as
 * a guard's detachment. Clicking a client lists the guards deployed there.
 *
 * @property-read Client|null $viewing
 * @property-read Collection<int, EmployeeProfile> $guards
 * @property-read array{rows: array<int, array{state: string, label: string, detail: string|null, tone: string, attention: bool}>, relief: list<array{reliever: string, for: string, post: string|null}>} $today
 */
#[Title('Clients')]
class Clients extends Component
{
    use InteractsWithUser;

    /** The region tab; '' = all regions. */
    #[Url(as: 'region')]
    public string $tab = '';

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $region = '';

    public string $province = '';

    public string $name = '';

    public string $site_address = '';

    /** "14.5547, 121.0244", pasted from Google Maps. */
    public string $coordinates = '';

    public string $geofence_meters = '150';

    public string $contact_person = '';

    public string $contact_number = '';

    /** The client whose guards are open. */
    public ?int $viewingId = null;

    private const FIELDS = ['editingId', 'region', 'province', 'name', 'site_address', 'coordinates', 'geofence_meters', 'contact_person', 'contact_number'];

    public function selectTab(string $region): void
    {
        $this->tab = Region::tryFrom($region) !== null ? $region : '';
    }

    public function create(): void
    {
        $this->authorize(Ability::ManageClients->value);

        $this->reset(self::FIELDS);
        // Adding from a region's tab starts in that region.
        $this->region = $this->tab;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Ability::ManageClients->value);

        $client = Client::query()->findOrFail($id);
        $this->editingId = $client->id;
        $this->region = $client->region->value;
        $this->province = $client->province;
        $this->name = $client->name;
        $this->site_address = (string) $client->site_address;
        $this->coordinates = $client->hasGeofence() ? (float) $client->latitude.', '.(float) $client->longitude : '';
        $this->geofence_meters = (string) $client->geofence_meters;
        $this->contact_person = (string) $client->contact_person;
        $this->contact_number = (string) $client->contact_number;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function viewGuards(int $id): void
    {
        $this->authorize(Ability::ManageClients->value);

        $this->viewingId = Client::query()->findOrFail($id)->id;
    }

    public function closeGuards(): void
    {
        $this->reset('viewingId');
    }

    /** A different region has different provinces. */
    public function updatedRegion(): void
    {
        $this->province = '';
    }

    public function cancel(): void
    {
        $this->reset(['formOpen', ...self::FIELDS]);
    }

    public function save(SaveClient $saveClient): void
    {
        $this->authorize(Ability::ManageClients->value);

        $region = Region::tryFrom($this->region);
        // "+63 917-123-4567" is saved as 0917 123 4567; a landline keeps its own spacing.
        $this->contact_number = PhilippineFormats::phoneNumber($this->contact_number) ?? trim($this->contact_number);
        $this->contact_person = PhilippineFormats::name($this->contact_person);

        $validated = $this->validate([
            'region' => ['required', Rule::enum(Region::class)],
            'province' => ['required', Rule::in($region?->provinces() ?? [])],
            'name' => ['required', 'string', 'max:150'],
            'site_address' => ['nullable', 'string', 'max:255'],
            // Two numbers, as Google Maps copies them: "14.5547, 121.0244".
            'coordinates' => ['nullable', 'string', 'regex:/^\s*-?\d{1,2}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?\s*$/'],
            'geofence_meters' => ['required', 'integer', 'min:30', 'max:2000'],
            'contact_person' => ['nullable', 'string', 'max:255', 'regex:'.PhilippineFormats::NAME_PATTERN],
            'contact_number' => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) {
                if (PhilippineFormats::phoneNumber((string) $value) === null) {
                    $fail('Enter a mobile (0917 123 4567) or a landline with its area code ((02) 8123 4567).');
                }
            }],
        ], [
            'contact_person.regex' => PhilippineFormats::NAME_MESSAGE,
            'province.required' => 'Choose the '.strtolower($region?->areaLabel() ?? 'province').'.',
            'province.in' => 'Choose the '.strtolower($region?->areaLabel() ?? 'province').'.',
            'coordinates.regex' => 'Paste the coordinates as "latitude, longitude", e.g. 14.5547, 121.0244.',
        ]);

        [$latitude, $longitude] = filled($validated['coordinates']) ? array_map(fn (string $part) => (float) trim($part), explode(',', $validated['coordinates'])) : [null, null];

        $client = $saveClient->handle($this->editingId !== null ? Client::query()->findOrFail($this->editingId) : null, [
            'region' => Region::from($validated['region']),
            'province' => $validated['province'],
            'name' => trim($validated['name']),
            'site_address' => filled($validated['site_address']) ? trim($validated['site_address']) : null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'geofence_meters' => (int) $validated['geofence_meters'],
            'contact_person' => filled($validated['contact_person']) ? trim($validated['contact_person']) : null,
            'contact_number' => filled($validated['contact_number']) ? trim($validated['contact_number']) : null,
        ], $this->currentUser());

        $this->cancel();
        $this->tab = $client->region->value;
        unset($this->clients, $this->regionCounts);
        $this->dispatch('notify', message: 'Client saved.');
    }

    public function delete(int $id, DeleteClient $deleteClient): void
    {
        $this->authorize(Ability::ManageClients->value);

        $deleteClient->handle(Client::query()->findOrFail($id), $this->currentUser());
        unset($this->clients, $this->regionCounts);
        $this->dispatch('notify', message: 'Client deleted.');
    }

    /**
     * Clients per region, for the tab badges. Only regions that have some get a tab.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function regionCounts(): array
    {
        $counts = Client::query()->toBase()->selectRaw('region, count(*) as total')->groupBy('region')->pluck('total', 'region');

        return collect(Region::cases())
            ->filter(fn (Region $region) => isset($counts[$region->value]))
            ->mapWithKeys(fn (Region $region) => [$region->value => (int) $counts[$region->value]])
            ->all();
    }

    /**
     * The open tab's clients, grouped "Region → Province".
     *
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Client::query()
            ->withCount('employeeProfiles')
            ->when(Region::tryFrom($this->tab), fn ($query, Region $region) => $query->where('region', $region->value))
            ->orderBy('province')
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Client $client) => array_search($client->region, Region::cases(), true))
            ->values();
    }

    #[Computed]
    public function viewing(): ?Client
    {
        return $this->viewingId !== null ? Client::query()->find($this->viewingId) : null;
    }

    /**
     * Everyone deployed to the open client, by post then name. Active staff
     * first; anyone who has left but is still linked is listed after them.
     *
     * @return Collection<int, EmployeeProfile>
     */
    #[Computed]
    public function guards(): Collection
    {
        if ($this->viewingId === null) {
            return new Collection;
        }

        return EmployeeProfile::query()
            ->where('client_id', $this->viewingId)
            ->with(['shift', 'user'])
            ->orderBy('post')
            ->orderBy('name')
            ->get()
            ->sortBy(fn (EmployeeProfile $profile) => $profile->employment_status->isActive() ? 0 : 1)
            ->values();
    }

    /**
     * Each guard's attendance right now, and today's relievers at this site.
     *
     * @return array{rows: array<int, array{state: string, label: string, detail: string|null, tone: string, attention: bool}>, relief: list<array{reliever: string, for: string, post: string|null}>}
     */
    #[Computed]
    public function today(): array
    {
        return $this->viewing !== null
            ? app(ClientAttendanceTodayQuery::class)->handle($this->viewing, $this->guards)
            : ['rows' => [], 'relief' => []];
    }

    public function render(): View
    {
        return view('livewire.admin.clients', [
            'selectedRegion' => Region::tryFrom($this->region),
        ]);
    }
}
