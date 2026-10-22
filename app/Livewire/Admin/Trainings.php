<?php

namespace App\Livewire\Admin;

use App\Actions\Training\DecideTrainingRequest;
use App\Actions\Training\DeleteTraining;
use App\Actions\Training\EnrollTrainee;
use App\Actions\Training\MarkTrainingResult;
use App\Actions\Training\RemoveTrainee;
use App\Actions\Training\SaveTraining;
use App\Enums\Ability;
use App\Enums\RequestStatus;
use App\Enums\TrainingAttendance;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\EmployeeProfile;
use App\Models\Training;
use App\Models\TrainingAttendee;
use App\Models\TrainingRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Guards' training sessions and who completed them.
 *
 * @property-read Collection<int, Training> $trainings
 * @property-read Training|null $managing
 * @property-read Collection<int, EmployeeProfile> $enrollable
 * @property-read Collection<int, TrainingRequest> $requests
 * @property-read TrainingRequest|null $answering
 * @property-read Collection<int, Training> $upcoming
 */
#[Title('Training')]
class Trainings extends Component
{
    use InteractsWithUser;

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $provider = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $venue = '';

    public string $validUntil = '';

    public string $notes = '';

    /** The session whose attendees are open. */
    public ?int $managingId = null;

    public string $enrollProfileId = '';

    /** The guard's training request being answered, and how. */
    public ?int $answeringId = null;

    /** An upcoming session to add them to, or '' for "approve, add them later". */
    public string $sessionId = '';

    public string $reviewNote = '';

    private const FIELDS = ['editingId', 'title', 'provider', 'startsOn', 'endsOn', 'venue', 'validUntil', 'notes'];

    public function create(): void
    {
        $this->authorize(Ability::ManageTraining->value);

        $this->reset(self::FIELDS);
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Ability::ManageTraining->value);

        $training = Training::query()->findOrFail($id);
        $this->editingId = $training->id;
        $this->title = $training->title;
        $this->provider = (string) $training->provider;
        $this->startsOn = $training->starts_on->toDateString();
        $this->endsOn = (string) $training->ends_on?->toDateString();
        $this->venue = (string) $training->venue;
        $this->validUntil = (string) $training->valid_until?->toDateString();
        $this->notes = (string) $training->notes;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->reset(['formOpen', ...self::FIELDS]);
    }

    public function save(SaveTraining $save): void
    {
        $this->authorize(Ability::ManageTraining->value);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'validUntil' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['startsOn' => 'first day', 'endsOn' => 'last day', 'validUntil' => 'valid until']);

        $blank = fn (string $value): ?string => filled($value) ? trim($value) : null;

        $save->handle($this->editingId !== null ? Training::query()->findOrFail($this->editingId) : null, [
            'title' => trim($validated['title']),
            'provider' => $blank($validated['provider'] ?? ''),
            'starts_on' => $validated['startsOn'],
            'ends_on' => $blank($validated['endsOn'] ?? ''),
            'venue' => $blank($validated['venue'] ?? ''),
            'valid_until' => $blank($validated['validUntil'] ?? ''),
            'notes' => $blank($validated['notes'] ?? ''),
        ], $this->currentUser());

        $this->cancel();
        unset($this->trainings);
        $this->dispatch('notify', message: 'Training saved.');
    }

    public function delete(int $id, DeleteTraining $delete): void
    {
        $this->authorize(Ability::ManageTraining->value);

        $delete->handle(Training::query()->findOrFail($id), $this->currentUser());
        unset($this->trainings);
        $this->dispatch('notify', message: 'Training deleted.');
    }

    // ---- Attendees ------------------------------------------------------

    public function manage(int $id): void
    {
        $this->authorize(Ability::ManageTraining->value);

        $this->managingId = Training::query()->findOrFail($id)->id;
        $this->enrollProfileId = '';
        $this->resetErrorBag();
    }

    public function closeManage(): void
    {
        $this->reset(['managingId', 'enrollProfileId']);
        unset($this->trainings);
    }

    public function enroll(EnrollTrainee $enroll): void
    {
        $this->authorize(Ability::ManageTraining->value);

        $this->validate(['enrollProfileId' => ['required', 'integer', 'exists:employee_profiles,id']], ['enrollProfileId.required' => 'Choose a guard.']);

        $enroll->handle(Training::query()->findOrFail($this->managingId), EmployeeProfile::query()->findOrFail((int) $this->enrollProfileId), $this->currentUser());

        $this->enrollProfileId = '';
        // Enrolling links a request of theirs that was waiting for a session.
        unset($this->managing, $this->enrollable, $this->requests);
    }

    public function mark(int $attendeeId, string $status, MarkTrainingResult $markResult): void
    {
        $this->authorize(Ability::ManageTraining->value);

        validator(['status' => $status], ['status' => [Rule::enum(TrainingAttendance::class)]])->validate();

        $markResult->handle($this->attendee($attendeeId), TrainingAttendance::from($status), $this->currentUser());
        unset($this->managing);
    }

    public function remove(int $attendeeId, RemoveTrainee $removeTrainee): void
    {
        $this->authorize(Ability::ManageTraining->value);

        $removeTrainee->handle($this->attendee($attendeeId), $this->currentUser());
        unset($this->managing, $this->enrollable);
    }

    // ---- Guards' requests -------------------------------------------------

    public function answer(int $id): void
    {
        $this->authorize(Ability::ManageTraining->value);

        $this->answeringId = TrainingRequest::query()->pending()->findOrFail($id)->id;
        $this->sessionId = '';
        $this->reviewNote = '';
        $this->resetErrorBag();
    }

    public function closeAnswer(): void
    {
        $this->reset(['answeringId', 'sessionId', 'reviewNote']);
        $this->resetErrorBag();
    }

    public function approveRequest(DecideTrainingRequest $decide): void
    {
        $this->decideRequest($decide, RequestStatus::Approved);
    }

    public function declineRequest(DecideTrainingRequest $decide): void
    {
        $this->decideRequest($decide, RequestStatus::Rejected);
    }

    private function decideRequest(DecideTrainingRequest $decide, RequestStatus $decision): void
    {
        $this->authorize(Ability::ManageTraining->value);

        $validated = $this->validate([
            'sessionId' => ['nullable', 'integer', 'exists:trainings,id'],
            'reviewNote' => ['nullable', 'string', 'max:1000'],
        ], [], ['sessionId' => 'session', 'reviewNote' => 'note']);

        $session = $decision === RequestStatus::Approved && filled($validated['sessionId']) ? Training::query()->findOrFail((int) $validated['sessionId']) : null;
        $request = $decide->handle($this->currentUser(), TrainingRequest::query()->findOrFail($this->answeringId), $decision, $session, filled($validated['reviewNote']) ? trim($validated['reviewNote']) : null);

        $this->closeAnswer();
        unset($this->requests, $this->trainings);
        $this->dispatch('notify', message: match (true) {
            $decision === RequestStatus::Rejected => "Declined. {$request->user->name} has been told why.",
            $session !== null => "Added {$request->user->name} to {$session->title}. They've been told.",
            default => "Approved. Add {$request->user->name} to a session when there's one: they'll be told then.",
        });
    }

    /**
     * Pending guards' requests, oldest first, then those approved and waiting for a session.
     *
     * @return Collection<int, TrainingRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        return TrainingRequest::query()
            ->with('user.employeeProfile')
            ->where(fn ($query) => $query->where('status', RequestStatus::Pending)
                ->orWhere(fn ($waiting) => $waiting->where('status', RequestStatus::Approved)->whereNull('training_id')))
            ->orderByRaw('case when status = ? then 0 else 1 end', [RequestStatus::Pending->value])
            ->oldest()
            ->get();
    }

    #[Computed]
    public function answering(): ?TrainingRequest
    {
        return $this->answeringId !== null ? TrainingRequest::query()->with('user.employeeProfile')->find($this->answeringId) : null;
    }

    /**
     * Sessions not over yet, soonest first: where an approved request can go.
     *
     * @return Collection<int, Training>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        return Training::query()
            ->where(fn ($query) => $query->whereDate('ends_on', '>=', today())->orWhere(fn ($single) => $single->whereNull('ends_on')->whereDate('starts_on', '>=', today())))
            ->orderBy('starts_on')
            ->get();
    }

    private function attendee(int $id): TrainingAttendee
    {
        return TrainingAttendee::query()->where('training_id', $this->managingId)->findOrFail($id);
    }

    /**
     * Upcoming and ongoing first (soonest on top), then past ones (latest on top).
     *
     * @return Collection<int, Training>
     */
    #[Computed]
    public function trainings(): Collection
    {
        $today = today();

        return Training::query()
            ->withCount(['attendees', 'attendees as completed_count' => fn ($query) => $query->where('status', TrainingAttendance::Completed)])
            ->orderByDesc('starts_on')
            ->get()
            ->sortBy(fn (Training $training) => ($training->ends_on ?? $training->starts_on)->lt($today) ? 1 : 0)
            ->values();
    }

    #[Computed]
    public function managing(): ?Training
    {
        return $this->managingId !== null
            ? Training::query()->with(['attendees' => fn ($query) => $query->with('profile')->orderBy('id')])->find($this->managingId)
            : null;
    }

    /**
     * Active guards not in the open session yet.
     *
     * @return Collection<int, EmployeeProfile>
     */
    #[Computed]
    public function enrollable(): Collection
    {
        return EmployeeProfile::query()
            ->active()
            ->where('is_office_staff', false)
            ->whereDoesntHave('trainingAttendances', fn ($query) => $query->where('training_id', $this->managingId))
            ->orderBy('name')
            ->get(['id', 'name', 'employee_number', 'detachment']);
    }

    public function render(): View
    {
        return view('livewire.admin.trainings');
    }
}
