<?php

namespace App\Livewire\Overtime;

use App\Actions\Overtime\SubmitOvertimeRequest;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\OvertimeRequest;
use App\Support\Heads;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ask for overtime. It goes to the person's head automatically (App\Support\Heads): Operations
 * for a guard, HR for Admin or Accounting, and so on. Nobody picks a supervisor.
 *
 * @property-read string|null $post
 * @property-read Collection<int, OvertimeRequest> $requests
 */
#[Title('My Overtime')]
class MyOvertime extends Component
{
    use InteractsWithUser;

    #[Url]
    public string $date = '';

    public string $hours = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->date = $this->date !== '' ? $this->date : CarbonImmutable::today()->toDateString();
    }

    public function submit(SubmitOvertimeRequest $submitOvertime): void
    {
        $validated = $this->validate([
            'date' => ['required', 'date', 'after_or_equal:'.CarbonImmutable::today()->subDays(7)->toDateString(), 'before_or_equal:'.CarbonImmutable::today()->addDays(30)->toDateString()],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:12'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $submitOvertime->handle(
            $this->currentUser(),
            CarbonImmutable::parse($validated['date']),
            (int) round((float) $validated['hours'] * 60),
            $validated['reason'],
            $this->post,
        );

        $this->reset(['hours', 'reason']);
        unset($this->requests);
        $this->dispatch('notify', message: 'Sent to '.$this->sentTo().'. Only approved overtime is paid.');
    }

    /** Where it goes, automatically: their head (Heads), e.g. "Operations" for a guard, "HR" for Accounting. */
    public function sentTo(): string
    {
        return Heads::label($this->currentUser());
    }

    /**
     * "Detachment · Post", snapshotted onto the request when it is filed.
     */
    #[Computed]
    public function post(): ?string
    {
        $profile = $this->currentUser()->loadMissing('employeeProfile')->employeeProfile;
        $post = collect([$profile?->detachment, $profile?->post])->filter()->implode(' · ');

        return $post !== '' ? $post : null;
    }

    /**
     * @return Collection<int, OvertimeRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        return $this->currentUser()->overtimeRequests()->with('supervisor')->latest('date')->limit(50)->get();
    }

    public function render(): View
    {
        return view('livewire.overtime.my-overtime');
    }
}
