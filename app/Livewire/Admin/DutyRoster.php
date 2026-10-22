<?php

namespace App\Livewire\Admin;

use App\Actions\People\ShiftForTimes;
use App\Actions\Roster\FillRosterWeek;
use App\Actions\Roster\RecordManualAttendance;
use App\Actions\Roster\RemoveDuty;
use App\Actions\Roster\SaveDuty;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\Client;
use App\Models\DutyAssignment;
use App\Models\PatrolCheckpoint;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operations' week grid: one row per guard, one cell per day. A cell is the
 * guard's duty (shift, post), a relief duty, or a day off. Past cells show
 * what actually happened, and a missed punch can be recorded by hand.
 *
 * A Satellite Operation sees and schedules only its region.
 *
 * @property-read list<CarbonImmutable> $days
 * @property-read Collection<int, User> $guards
 * @property-read BaseCollection<string, DutyAssignment> $duties
 * @property-read BaseCollection<string, TimeLog> $logs
 * @property-read Collection<int, Shift> $shifts
 * @property-read Collection<int, Client> $clients
 * @property-read User|null $editingGuard
 * @property-read DutyAssignment|null $manualDuty
 * @property-read array{client: Client, checkpoints: int, interval: int}|null $patrolSetup
 */
#[Title('Duty Roster')]
class DutyRoster extends Component
{
    use InteractsWithUser;

    /** Monday of the week shown (Y-m-d). */
    #[Url(as: 'week', except: '')]
    public string $week = '';

    /** Only guards whose usual client is this one ('' = all). */
    #[Url(as: 'client', except: '')]
    public string $clientFilter = '';

    // Cell editor
    public ?int $editUserId = null;

    public string $editDate = '';

    /** A shift's id, or self::CUSTOM_SHIFT to type the times (like HR's employee form). */
    public string $editShiftId = '';

    public const CUSTOM_SHIFT = 'custom';

    /** Typed times when editShiftId is "custom" (HH:MM, 24-hour). */
    public string $editCustomStart = '';

    public string $editCustomEnd = '';

    public string $editCustomGrace = '10';

    public string $editClientId = '';

    public string $editPost = '';

    public bool $editPatrols = false;

    // Manual attendance
    public ?int $manualDutyId = null;

    public string $manualIn = '';

    public string $manualOut = '';

    public string $manualReason = '';

    public function mount(): void
    {
        $this->week = $this->weekStart()->toDateString();
    }

    public function shiftWeek(int $weeks): void
    {
        $this->week = $this->weekStart()->addWeeks($weeks)->toDateString();
        $this->clearComputed();
    }

    public function thisWeek(): void
    {
        $this->week = CarbonImmutable::today()->startOfWeek()->toDateString();
        $this->clearComputed();
    }

    public function updatedClientFilter(): void
    {
        $this->clearComputed();
    }

    // ---- One cell -------------------------------------------------------

    public function editCell(int $userId, string $date): void
    {
        $this->authorize(Ability::ManageRoster->value);

        $guard = $this->guards->firstWhere('id', $userId);
        abort_if($guard === null, 404);

        $duty = $this->duties->get($userId.'|'.$date);
        $this->editUserId = $guard->id;
        $this->editDate = CarbonImmutable::parse($date)->toDateString();
        $this->editShiftId = (string) ($duty->shift_id ?? $guard->employeeProfile?->shift_id);
        $this->editClientId = (string) ($duty !== null ? $duty->client_id : $guard->employeeProfile?->client_id);
        $this->editPost = (string) ($duty !== null ? $duty->post : $guard->employeeProfile?->post);
        $this->editPatrols = $duty !== null ? $duty->patrols : str_contains(strtolower($this->editPost), 'roving');
        $this->resetErrorBag();
    }

    /**
     * What a roving duty at the chosen client means: how many stickers and how
     * often, so the choice is made knowing whether the client is set up for it.
     *
     * @return array{client: Client, checkpoints: int, interval: int}|null
     */
    #[Computed]
    public function patrolSetup(): ?array
    {
        $client = $this->editClientId !== '' ? $this->clients->firstWhere('id', (int) $this->editClientId) : null;

        if ($client === null) {
            return null;
        }

        return [
            'client' => $client,
            'checkpoints' => PatrolCheckpoint::query()->where('client_id', $client->id)->active()->count(),
            'interval' => max(15, $client->patrol_interval_minutes),
        ];
    }

    /**
     * "Custom time" chosen: start from the times picked before (or the company
     * default's), so a small change, e.g. 9–6 to 10–7, is one edit.
     */
    public function updatingEditShiftId(string $value): void
    {
        if ($value !== self::CUSTOM_SHIFT || $this->editShiftId === self::CUSTOM_SHIFT) {
            return;
        }

        $shift = ctype_digit($this->editShiftId) ? Shift::query()->find((int) $this->editShiftId) : null;
        $shift ??= Shift::fallback();

        $this->editCustomStart = substr($shift->start_time, 0, 5);
        $this->editCustomEnd = substr($shift->end_time, 0, 5);
        $this->editCustomGrace = (string) $shift->grace_minutes;
    }

    public function closeCell(): void
    {
        $this->reset(['editUserId', 'editDate', 'editShiftId', 'editCustomStart', 'editCustomEnd', 'editCustomGrace', 'editClientId', 'editPost', 'editPatrols']);
    }

    public function saveCell(SaveDuty $save, ShiftForTimes $shiftForTimes): void
    {
        $this->authorize(Ability::ManageRoster->value);

        $custom = $this->editShiftId === self::CUSTOM_SHIFT;

        $this->validate([
            'editShiftId' => $custom ? ['required'] : ['required', 'integer', 'exists:shifts,id'],
            'editCustomStart' => $custom ? ['required', 'date_format:H:i'] : [],
            'editCustomEnd' => $custom ? ['required', 'date_format:H:i', 'different:editCustomStart'] : [],
            'editCustomGrace' => $custom ? ['required', 'integer', 'min:0', 'max:120'] : [],
            'editClientId' => ['nullable', 'integer', 'exists:clients,id'],
            'editPost' => ['nullable', 'string', 'max:255'],
            'editPatrols' => ['boolean'],
        ], [
            'editShiftId.required' => 'Choose the duty.',
            'editCustomStart.required' => 'Enter the start time.',
            'editCustomEnd.required' => 'Enter the end time.',
            'editCustomEnd.different' => 'The end can\'t be the same as the start.',
        ], ['editCustomStart' => 'start time', 'editCustomEnd' => 'end time', 'editCustomGrace' => 'grace minutes']);

        // Typed times: the shift with exactly these times, reused when it exists (as HR's form does).
        $shift = $custom
            ? $shiftForTimes->handle($this->editCustomStart, $this->editCustomEnd, (int) $this->editCustomGrace, $this->currentUser())
            : Shift::query()->findOrFail((int) $this->editShiftId);

        $save->handle(
            $this->guard(),
            CarbonImmutable::parse($this->editDate),
            $shift,
            $this->editClientId !== '' ? Client::query()->find((int) $this->editClientId) : null,
            $this->editPost,
            $this->currentUser(),
            $this->editPatrols,
        );

        $this->closeCell();
        $this->clearComputed();
        $this->dispatch('notify', message: 'Duty saved.');
    }

    public function dayOff(RemoveDuty $remove): void
    {
        $this->authorize(Ability::ManageRoster->value);

        $duty = $this->duties->get($this->editUserId.'|'.$this->editDate);

        if ($duty !== null) {
            $remove->handle($duty, $this->currentUser());
        }

        $this->closeCell();
        $this->clearComputed();
        $this->dispatch('notify', message: 'Marked as a day off.');
    }

    // ---- Whole week -----------------------------------------------------

    public function fillWeek(string $mode, FillRosterWeek $fill): void
    {
        $this->authorize(Ability::ManageRoster->value);

        $created = $fill->handle($this->guards, $this->weekStart(), $mode, $this->currentUser());

        $this->clearComputed();
        $this->dispatch('notify', message: $created > 0 ? "{$created} ".str('duty')->plural($created).' added. Days already set were left as they are.' : 'Nothing to add: every open day is already set.');
    }

    // ---- Manual attendance ----------------------------------------------

    public function startManual(int $dutyId): void
    {
        $this->authorize(Ability::ManageRoster->value);

        $duty = $this->duties->firstWhere('id', $dutyId);
        abort_if($duty === null, 404);

        $log = $this->logs->get($duty->user_id.'|'.$duty->duty_date->toDateString());
        $this->manualDutyId = $duty->id;
        $this->manualIn = $log?->clock_in_at?->format('Y-m-d\TH:i') ?? $duty->duty_date->setTimeFromTimeString($duty->shift->start_time)->format('Y-m-d\TH:i');
        $this->manualOut = (string) $log?->clock_out_at?->format('Y-m-d\TH:i');
        $this->manualReason = (string) $log?->manual_reason;
        $this->resetErrorBag();
    }

    public function closeManual(): void
    {
        $this->reset(['manualDutyId', 'manualIn', 'manualOut', 'manualReason']);
    }

    public function saveManual(RecordManualAttendance $record): void
    {
        $this->authorize(Ability::ManageRoster->value);

        $this->validate([
            'manualIn' => ['required', 'date'],
            'manualOut' => ['nullable', 'date'],
            'manualReason' => ['required', 'string', 'max:1000'],
        ], [], ['manualIn' => 'clock-in', 'manualOut' => 'clock-out', 'manualReason' => 'reason']);

        $record->handle(
            DutyAssignment::query()->findOrFail($this->manualDutyId),
            CarbonImmutable::parse($this->manualIn),
            $this->manualOut !== '' ? CarbonImmutable::parse($this->manualOut) : null,
            $this->manualReason,
            $this->currentUser(),
        );

        $this->closeManual();
        $this->clearComputed();
        $this->dispatch('notify', message: 'Attendance recorded and marked as manual.');
    }

    // ---- Data -----------------------------------------------------------

    private function weekStart(): CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) === 1 ? CarbonImmutable::parse($this->week) : CarbonImmutable::today();

        return $parsed->startOfWeek();
    }

    private function guard(): User
    {
        $guard = $this->guards->firstWhere('id', $this->editUserId);
        abort_if($guard === null, 404);

        return $guard;
    }

    private function clearComputed(): void
    {
        unset($this->days, $this->guards, $this->duties, $this->logs);
    }

    /**
     * @return list<CarbonImmutable>
     */
    #[Computed]
    public function days(): array
    {
        $start = $this->weekStart();

        return array_map(fn (int $offset) => $start->addDays($offset), range(0, 6));
    }

    /**
     * Active guards this viewer may schedule, grouped by their usual client.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function guards(): Collection
    {
        return User::query()
            ->onPayroll()
            ->visibleTo($this->currentUser())
            ->whereHas('employeeProfile', fn ($profile) => $profile->where('is_office_staff', false)
                ->when($this->clientFilter !== '', fn ($query) => $query->where('client_id', (int) $this->clientFilter)))
            ->with(['employeeProfile.client', 'employeeProfile.shift'])
            ->orderBy('name')
            ->get()
            ->sortBy(fn (User $user) => ($user->employeeProfile->detachment ?? 'zzz').'|'.$user->name)
            ->values();
    }

    /**
     * @return BaseCollection<string, DutyAssignment>
     */
    #[Computed]
    public function duties(): BaseCollection
    {
        [$from, $to] = [$this->days[0], $this->days[6]];

        return DutyAssignment::query()
            ->with(['shift', 'client'])
            ->whereIn('user_id', $this->guards->pluck('id'))
            ->whereDate('duty_date', '>=', $from->toDateString())
            ->whereDate('duty_date', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn (DutyAssignment $duty) => $duty->user_id.'|'.$duty->duty_date->toDateString());
    }

    /**
     * What actually happened on each day shown.
     *
     * @return BaseCollection<string, TimeLog>
     */
    #[Computed]
    public function logs(): BaseCollection
    {
        return TimeLog::query()
            ->whereIn('user_id', $this->guards->pluck('id'))
            ->between($this->days[0], $this->days[6])
            ->get()
            ->keyBy(fn (TimeLog $log) => $log->user_id.'|'.$log->log_date->toDateString());
    }

    /**
     * @return Collection<int, Shift>
     */
    #[Computed]
    public function shifts(): Collection
    {
        return Shift::query()->orderBy('start_time')->get();
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
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function editingGuard(): ?User
    {
        return $this->editUserId !== null ? $this->guards->firstWhere('id', $this->editUserId) : null;
    }

    #[Computed]
    public function manualDuty(): ?DutyAssignment
    {
        return $this->manualDutyId !== null ? $this->duties->firstWhere('id', $this->manualDutyId) : null;
    }

    public function render(): View
    {
        return view('livewire.admin.duty-roster');
    }
}
