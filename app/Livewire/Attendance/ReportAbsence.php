<?php

namespace App\Livewire\Attendance;

use App\Actions\Relievers\CancelAbsenceNotice;
use App\Actions\Relievers\SubmitAbsenceNotice;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\AbsenceNotice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A guard tells Operations ahead of time that they can't report, so a
 * reliever can be sent to the post.
 *
 * @property-read Collection<int, AbsenceNotice> $notices
 */
#[Title('Report Absence')]
class ReportAbsence extends Component
{
    use InteractsWithUser;

    public string $dutyDate = '';

    public string $reason = '';

    public function submit(SubmitAbsenceNotice $submit): void
    {
        $validated = $this->validate([
            'dutyDate' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], ['dutyDate' => 'duty date']);

        $submit->handle($this->currentUser(), CarbonImmutable::parse($validated['dutyDate']), $validated['reason']);

        $this->reset(['dutyDate', 'reason']);
        unset($this->notices);
        $this->dispatch('notify', message: 'Operations has been told. You\'ll see here when a reliever is assigned.');
    }

    public function cancel(int $id, CancelAbsenceNotice $cancel): void
    {
        // Looked up through the user: another guard's notice is simply not found.
        $notice = AbsenceNotice::query()->where('user_id', $this->currentUser()->id)->findOrFail($id);

        $cancel->handle($notice, $this->currentUser());

        unset($this->notices);
        $this->dispatch('notify', message: 'Notice cancelled.');
    }

    /**
     * @return Collection<int, AbsenceNotice>
     */
    #[Computed]
    public function notices(): Collection
    {
        return AbsenceNotice::query()->where('user_id', $this->currentUser()->id)->with('reliever')->latest('duty_date')->limit(30)->get();
    }

    public function render(): View
    {
        $profile = $this->currentUser()->employeeProfile()->first();

        return view('livewire.attendance.report-absence', [
            'deployed' => $profile !== null && $profile->hasDeployment(),
            'place' => $profile?->assignmentLabel(),
            'maxDate' => today()->addDays(SubmitAbsenceNotice::MAX_DAYS_AHEAD)->toDateString(),
        ]);
    }
}
