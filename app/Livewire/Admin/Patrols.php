<?php

namespace App\Livewire\Admin;

use App\Actions\Patrols\RemoveCheckpoint;
use App\Actions\Patrols\RenewCheckpointCode;
use App\Actions\Patrols\SaveCheckpoint;
use App\Actions\Patrols\SetPatrolInterval;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Client;
use App\Models\PatrolCheckpoint;
use App\Models\PatrolScan;
use App\Models\TimeLog;
use App\Queries\Attendance\PatrolRoundsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operations' live view of who is actually working between clock-in and
 * clock-out: roving guards' patrol rounds, and guards away from the post
 * (AwayMonitor, embedded). Plus the checkpoints themselves.
 *
 * A Satellite Operation sees its region only.
 *
 * @property-read list<array{log: TimeLog, rounds: list<array{index: int, from: CarbonImmutable, to: CarbonImmutable, state: string, scanned: list<int>, missing: list<string>, suspicious: string|null}>, total: int, last: PatrolScan|null}> $patrolling
 * @property-read Collection<int, Client> $clients
 * @property-read Client|null $client
 * @property-read Collection<int, PatrolCheckpoint> $checkpoints
 * @property-read Collection<int, PatrolScan> $scans
 */
#[Title('Patrols')]
class Patrols extends Component
{
    use InteractsWithUser;

    #[Url(as: 'tab', except: 'live')]
    public string $tab = 'live';

    #[Url(as: 'client', except: '')]
    public string $clientId = '';

    public string $interval = '120';

    public ?int $editingCheckpointId = null;

    public bool $checkpointFormOpen = false;

    public string $checkpointName = '';

    public string $checkpointCoordinates = '';

    public string $checkpointOrder = '10';

    public function mount(): void
    {
        // Open on a site that has checkpoints, if any does.
        $this->clientId = $this->clientId !== '' ? $this->clientId : (string) ($this->clients->firstWhere('checkpoints_count', '>', 0) ?? $this->clients->first())?->id;
        $this->interval = (string) ($this->client->patrol_interval_minutes ?? 120);
    }

    public function updatedClientId(): void
    {
        unset($this->client, $this->checkpoints);
        $this->interval = (string) ($this->client->patrol_interval_minutes ?? 120);
    }

    // ---- Checkpoints ----------------------------------------------------

    public function saveInterval(SetPatrolInterval $set): void
    {
        $this->authorize(Ability::ManagePatrols->value);
        $this->validate(['interval' => ['required', 'integer', 'min:30', 'max:720']], [], ['interval' => 'round interval']);

        $set->handle($this->requireClient(), (int) $this->interval, $this->currentUser());
        unset($this->client);
        $this->dispatch('notify', message: 'Round interval saved.');
    }

    public function createCheckpoint(): void
    {
        $this->authorize(Ability::ManagePatrols->value);

        $this->reset(['editingCheckpointId', 'checkpointName', 'checkpointCoordinates']);
        $this->checkpointOrder = (string) (((int) $this->checkpoints->max('sort_order')) + 10);
        $this->resetErrorBag();
        $this->checkpointFormOpen = true;
    }

    public function editCheckpoint(int $id): void
    {
        $this->authorize(Ability::ManagePatrols->value);

        $checkpoint = $this->checkpoints->firstWhere('id', $id);
        abort_if($checkpoint === null, 404);

        $this->editingCheckpointId = $checkpoint->id;
        $this->checkpointName = $checkpoint->name;
        $this->checkpointCoordinates = $checkpoint->hasLocation() ? (float) $checkpoint->latitude.', '.(float) $checkpoint->longitude : '';
        $this->checkpointOrder = (string) $checkpoint->sort_order;
        $this->resetErrorBag();
        $this->checkpointFormOpen = true;
    }

    public function closeCheckpoint(): void
    {
        $this->reset(['checkpointFormOpen', 'editingCheckpointId', 'checkpointName', 'checkpointCoordinates']);
    }

    public function saveCheckpoint(SaveCheckpoint $save): void
    {
        $this->authorize(Ability::ManagePatrols->value);

        $validated = $this->validate([
            'checkpointName' => ['required', 'string', 'max:100'],
            'checkpointCoordinates' => ['nullable', 'string', 'regex:/^\s*-?\d{1,2}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?\s*$/'],
            'checkpointOrder' => ['required', 'integer', 'min:0', 'max:10000'],
        ], ['checkpointCoordinates.regex' => 'Paste the coordinates as "latitude, longitude".'], ['checkpointName' => 'name', 'checkpointOrder' => 'order']);

        [$latitude, $longitude] = filled($validated['checkpointCoordinates']) ? array_map(fn (string $part) => (float) trim($part), explode(',', $validated['checkpointCoordinates'])) : [null, null];

        $save->handle(
            $this->editingCheckpointId !== null ? $this->checkpoints->firstWhere('id', $this->editingCheckpointId) : null,
            $this->requireClient(),
            $validated['checkpointName'],
            $latitude,
            $longitude,
            (int) $validated['checkpointOrder'],
            $this->currentUser(),
        );

        $this->closeCheckpoint();
        unset($this->checkpoints);
        $this->dispatch('notify', message: 'Checkpoint saved. Print its QR and stick it there.');
    }

    /** "New sticker": a new code, so the old QR (and any photo of it) stops working. */
    public function renewCheckpoint(int $id, RenewCheckpointCode $renew): void
    {
        $this->authorize(Ability::ManagePatrols->value);

        $checkpoint = $this->checkpoints->firstWhere('id', $id);
        abort_if($checkpoint === null, 404);

        $renew->handle($checkpoint, $this->currentUser());
        unset($this->checkpoints);
        $this->dispatch('notify', message: "New code for {$checkpoint->name}. Print its QR and replace the sticker: the old one no longer scans.");
    }

    // ---- A guard's scans ------------------------------------------------

    /** The duty whose scans (with their photos) are open. */
    public ?int $viewingScansLogId = null;

    public function viewScans(int $logId): void
    {
        $this->authorize(Ability::ManagePatrols->value);

        abort_unless(TimeLog::query()->whereKey($logId)->whereHas('user', fn ($user) => $user->visibleTo($this->currentUser()))->exists(), 404);
        $this->viewingScansLogId = $logId;
    }

    public function closeScans(): void
    {
        $this->reset('viewingScansLogId');
    }

    /**
     * The open duty's scans, newest first, with how far from the checkpoint each was.
     *
     * @return Collection<int, PatrolScan>
     */
    #[Computed]
    public function scans(): Collection
    {
        return $this->viewingScansLogId !== null
            ? PatrolScan::query()->where('time_log_id', $this->viewingScansLogId)->with(['checkpoint', 'user'])->latest('scanned_at')->get()
            : new Collection;
    }

    public function removeCheckpoint(int $id, RemoveCheckpoint $remove): void
    {
        $this->authorize(Ability::ManagePatrols->value);

        $checkpoint = $this->checkpoints->firstWhere('id', $id);
        abort_if($checkpoint === null, 404);

        $remove->handle($checkpoint, $this->currentUser());
        unset($this->checkpoints);
        $this->dispatch('notify', message: 'Checkpoint removed. Its QR no longer works.');
    }

    private function requireClient(): Client
    {
        abort_if($this->client === null, 404);

        return $this->client;
    }

    // ---- Data -----------------------------------------------------------

    /**
     * Roving guards clocked in right now, with their rounds.
     *
     * @return list<array{log: TimeLog, rounds: list<array{index: int, from: CarbonImmutable, to: CarbonImmutable, state: string, scanned: list<int>, missing: list<string>, suspicious: string|null}>, total: int, last: PatrolScan|null}>
     */
    #[Computed]
    public function patrolling(): array
    {
        $now = CarbonImmutable::now();
        $query = app(PatrolRoundsQuery::class);

        return array_values(TimeLog::query()
            ->whereNotNull('clock_in_at')->whereNull('clock_out_at')
            ->whereDate('log_date', '>=', $now->subDay()->toDateString())
            ->whereHas('dutyAssignment', fn ($duty) => $duty->where('patrols', true))
            ->whereHas('user', fn ($user) => $user->visibleTo($this->currentUser()))
            ->with(['user.employeeProfile', 'dutyAssignment.client', 'dutyAssignment.shift'])
            ->get()
            ->map(function (TimeLog $log) use ($query, $now) {
                $result = $query->handle($log, $now);

                return [
                    'log' => $log,
                    'rounds' => $result['rounds'],
                    'total' => $result['checkpoints']->count(),
                    'last' => PatrolScan::query()->with('checkpoint')->where('time_log_id', $log->id)->latest('scanned_at')->first(),
                ];
            })
            ->all());
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clients(): Collection
    {
        $user = $this->currentUser();

        return Client::query()
            ->when($user->isRegionallyLimited(), fn ($query) => $query->where('region', $user->operations_region->value ?? ''))
            ->withCount(['checkpoints' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function client(): ?Client
    {
        return $this->clients->firstWhere('id', (int) $this->clientId);
    }

    /**
     * @return Collection<int, PatrolCheckpoint>
     */
    #[Computed]
    public function checkpoints(): Collection
    {
        return $this->client !== null
            ? PatrolCheckpoint::query()->where('client_id', $this->client->id)->active()->withCount('scans')->orderBy('sort_order')->orderBy('name')->get()
            : new Collection;
    }

    public function render(): View
    {
        return view('livewire.admin.patrols');
    }
}
