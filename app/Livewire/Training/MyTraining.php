<?php

namespace App\Livewire\Training;

use App\Actions\Training\CancelTrainingRequest;
use App\Actions\Training\FileTrainingRequest;
use App\Enums\TrainingAttendance;
use App\Enums\TrainingRequestKind;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\TrainingAttendee;
use App\Models\TrainingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A guard's trainings: ask for one (a refresher, license renewal, first aid),
 * follow the answer, and see the sessions they're in or completed.
 *
 * @property-read Collection<int, TrainingRequest> $requests
 * @property-read Collection<int, TrainingAttendee> $sessions
 */
#[Title('My Training')]
class MyTraining extends Component
{
    use InteractsWithUser;

    public string $kind = '';

    public string $reason = '';

    public string $preferredFrom = '';

    public function mount(): void
    {
        // For guards: office staff ask HR directly.
        abort_unless($this->currentUser()->employeeProfile?->hasDeployment() === true, 404);
    }

    public function submit(FileTrainingRequest $file): void
    {
        $validated = $this->validate([
            'kind' => ['required', Rule::enum(TrainingRequestKind::class)],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'preferredFrom' => ['nullable', 'date', 'after_or_equal:today'],
        ], [], ['kind' => 'training', 'preferredFrom' => 'preferred start']);

        $file->handle($this->currentUser(), TrainingRequestKind::from($validated['kind']), trim($validated['reason']), filled($validated['preferredFrom']) ? CarbonImmutable::parse($validated['preferredFrom']) : null);

        $this->reset(['kind', 'reason', 'preferredFrom']);
        unset($this->requests);
        $this->dispatch('notify', message: 'Request sent to Admin. You\'ll be told when it\'s decided.');
    }

    public function cancel(int $id, CancelTrainingRequest $cancel): void
    {
        $cancel->handle($this->currentUser(), $id);
        unset($this->requests);
        $this->dispatch('notify', message: 'Request cancelled.');
    }

    /**
     * @return Collection<int, TrainingRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        return TrainingRequest::query()->where('user_id', $this->currentUser()->id)->with('training')->latest()->get();
    }

    /**
     * Sessions they're in or were in, latest first.
     *
     * @return Collection<int, TrainingAttendee>
     */
    #[Computed]
    public function sessions(): Collection
    {
        return TrainingAttendee::query()
            ->where('employee_profile_id', $this->currentUser()->employeeProfile?->id)
            ->with('training')
            ->get()
            ->sortByDesc(fn (TrainingAttendee $attendee) => $attendee->training->starts_on)
            ->values();
    }

    public function render(): View
    {
        return view('livewire.training.my-training', ['completed' => TrainingAttendance::Completed]);
    }
}
