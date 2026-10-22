<?php

namespace App\Livewire\Admin;

use App\Actions\Operations\DeleteRadio;
use App\Actions\Operations\SaveRadio;
use App\Enums\Ability;
use App\Enums\RadioStatus;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Client;
use App\Models\Radio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The NTC radio masterlist (Admin, Operation HQ): every two-way radio, its NTC permit
 * and expiry, and where it is. Permits nearing expiry come first and are alerted
 * (SendRadioPermitAlerts); a guard's radio shows on their resignation clearance.
 *
 * @property-read Collection<int, Radio> $radios
 * @property-read array<string, int> $counts
 * @property-read Collection<int, Client> $clients
 * @property-read Collection<int, User> $guards
 */
#[Title('Radios')]
class Radios extends Component
{
    use InteractsWithUser;

    /** "all", "due" (permit due within 30 days or expired), or a RadioStatus value. */
    #[Url(as: 'show', except: 'all')]
    public string $show = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $brand = '';

    public string $model = '';

    public string $serialNumber = '';

    public string $frequency = '';

    public string $permitNumber = '';

    public string $permitExpiresOn = '';

    public string $status = 'spare';

    public string $clientId = '';

    public string $assignedUserId = '';

    public string $notes = '';

    private const FIELDS = ['editingId', 'brand', 'model', 'serialNumber', 'frequency', 'permitNumber', 'permitExpiresOn', 'status', 'clientId', 'assignedUserId', 'notes'];

    public function mount(): void
    {
        $this->authorize(Ability::ManageRadios->value);
    }

    public function create(): void
    {
        $this->authorize(Ability::ManageRadios->value);

        $this->reset(self::FIELDS);
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Ability::ManageRadios->value);

        $radio = Radio::query()->findOrFail($id);
        $this->editingId = $radio->id;
        $this->brand = $radio->brand;
        $this->model = $radio->model;
        $this->serialNumber = $radio->serial_number;
        $this->frequency = (string) $radio->frequency;
        $this->permitNumber = (string) $radio->ntc_permit_number;
        $this->permitExpiresOn = (string) $radio->permit_expires_on?->toDateString();
        $this->status = $radio->status->value;
        $this->clientId = (string) $radio->client_id;
        $this->assignedUserId = (string) $radio->assigned_user_id;
        $this->notes = (string) $radio->notes;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset(['formOpen', ...self::FIELDS]);
    }

    public function save(SaveRadio $saveRadio): void
    {
        $this->authorize(Ability::ManageRadios->value);

        $validated = $this->validate([
            'brand' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'serialNumber' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/ ]*$/'],
            'frequency' => ['nullable', 'string', 'max:80'],
            'permitNumber' => ['nullable', 'string', 'max:80'],
            'permitExpiresOn' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::enum(RadioStatus::class)],
            'clientId' => ['nullable', 'integer', 'exists:clients,id'],
            'assignedUserId' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['serialNumber.regex' => 'Letters, numbers, dashes and slashes only, as printed on the radio.'], [
            'serialNumber' => 'serial number', 'permitNumber' => 'NTC permit number', 'permitExpiresOn' => 'permit expiry', 'clientId' => 'post', 'assignedUserId' => 'guard',
        ]);

        $trim = fn (string $value): ?string => filled($value) ? trim($value) : null;

        $saveRadio->handle($this->editingId !== null ? Radio::query()->findOrFail($this->editingId) : null, [
            'brand' => trim($validated['brand']),
            'model' => trim($validated['model']),
            'serial_number' => $validated['serialNumber'],
            'frequency' => $trim((string) $validated['frequency']),
            'ntc_permit_number' => $trim((string) $validated['permitNumber']),
            'permit_expires_on' => filled($validated['permitExpiresOn']) ? CarbonImmutable::parse($validated['permitExpiresOn']) : null,
            'status' => RadioStatus::from($validated['status']),
            'client_id' => filled($validated['clientId']) ? (int) $validated['clientId'] : null,
            'assigned_user_id' => filled($validated['assignedUserId']) ? (int) $validated['assignedUserId'] : null,
            'notes' => $trim((string) $validated['notes']),
        ], $this->currentUser());

        $this->cancel();
        unset($this->radios, $this->counts);
        $this->dispatch('notify', message: 'Radio saved.');
    }

    public function delete(int $id, DeleteRadio $deleteRadio): void
    {
        $this->authorize(Ability::ManageRadios->value);

        $deleteRadio->handle(Radio::query()->findOrFail($id), $this->currentUser());
        unset($this->radios, $this->counts);
        $this->dispatch('notify', message: 'Radio deleted.');
    }

    /**
     * Soonest permit expiry first (no date last), then by serial number.
     *
     * @return Collection<int, Radio>
     */
    #[Computed]
    public function radios(): Collection
    {
        $term = trim($this->search);

        return Radio::query()
            ->with(['client', 'assignedTo'])
            ->when($this->show === 'due', fn (Builder $query) => $query->permitDueBy(CarbonImmutable::today()->addDays(Radio::DUE_SOON_DAYS)))
            ->when(RadioStatus::tryFrom($this->show), fn (Builder $query, RadioStatus $status) => $query->where('status', $status))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('serial_number', 'like', "%{$term}%")->orWhere('brand', 'like', "%{$term}%")->orWhere('model', 'like', "%{$term}%")
                ->orWhere('ntc_permit_number', 'like', "%{$term}%")
                ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', "%{$term}%"))
                ->orWhereHas('assignedTo', fn (Builder $user) => $user->where('name', 'like', "%{$term}%"))))
            ->orderByRaw('permit_expires_on is null')->orderBy('permit_expires_on')->orderBy('serial_number')
            ->get();
    }

    /**
     * How many per filter: all, permit due, and each status.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $byStatus = Radio::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total)->all();

        return [
            'all' => array_sum($byStatus),
            'due' => Radio::query()->permitDueBy(CarbonImmutable::today()->addDays(Radio::DUE_SOON_DAYS))->count(),
            ...$byStatus,
        ];
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Client::query()->orderBy('name')->get();
    }

    /**
     * Guards who still work here, for "with a guard".
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function guards(): Collection
    {
        return User::query()->onPayroll()->fieldStaff()->orderBy('name')->get(['id', 'name']);
    }

    public function render(): View
    {
        return view('livewire.admin.radios');
    }
}
