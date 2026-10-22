<?php

namespace App\Livewire\Leave;

use App\Actions\Leave\AttachLeaveDocument;
use App\Actions\Leave\CancelLeaveRequest;
use App\Actions\Leave\SubmitLeaveRequest;
use App\Enums\LeaveType;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\LeaveRequest;
use App\Support\LeaveRules;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * File leave and follow it. The leaves under special laws show what the law
 * gives and the proof they need; proof can be attached now or later, and the
 * decider can't approve without it.
 */
#[Title('My Leave')]
class MyLeave extends Component
{
    use InteractsWithUser, WithFileUploads;

    public string $type = 'vacation';

    public string $start_date = '';

    public string $end_date = '';

    public string $reason = '';

    /** @var array<int, UploadedFile> proof, with the request */
    public array $documents = [];

    /** Attaching proof to a request already filed. */
    public ?int $attachingId = null;

    /** @var UploadedFile|null */
    public $attachment = null;

    /** The earliest start date for the chosen type: sick and emergency may have started already. */
    public function earliestStart(): CarbonImmutable
    {
        $type = LeaveType::tryFrom($this->type) ?? LeaveType::Vacation;

        return $type->canStartInThePast() ? CarbonImmutable::today()->subDays(LeaveRules::fileAfterDays()) : CarbonImmutable::today();
    }

    /** The days asked so far (for the sick-leave certificate hint), or null without both dates. */
    public function daysAsked(): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->start_date) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->end_date) !== 1) {
            return null;
        }

        $days = (int) CarbonImmutable::parse($this->start_date)->diffInDays(CarbonImmutable::parse($this->end_date), false) + 1;

        return $days > 0 ? $days : null;
    }

    public function submit(SubmitLeaveRequest $submitLeave): void
    {
        $validated = $this->validate([
            'type' => ['required', Rule::enum(LeaveType::class)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:1000'],
            'documents' => ['array', 'max:'.AttachLeaveDocument::MAX_FILES],
            'documents.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], ['documents.*.mimes' => 'Attach a photo (JPG, PNG) or a PDF.']);

        $submitLeave->handle(
            $this->currentUser(),
            LeaveType::from($validated['type']),
            CarbonImmutable::parse($validated['start_date']),
            CarbonImmutable::parse($validated['end_date']),
            $validated['reason'],
            array_values($this->documents),
        );

        $this->reset(['start_date', 'end_date', 'reason', 'type', 'documents']);
        unset($this->requests);
        $this->dispatch('notify', message: 'Leave request submitted.');
    }

    public function startAttaching(int $id): void
    {
        $this->attachingId = $this->currentUser()->leaveRequests()->findOrFail($id)->id;
        $this->reset('attachment');
        $this->resetErrorBag();
    }

    public function stopAttaching(): void
    {
        $this->reset(['attachingId', 'attachment']);
    }

    public function attach(AttachLeaveDocument $attach): void
    {
        $this->validate(['attachment' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']], ['attachment.mimes' => 'Attach a photo (JPG, PNG) or a PDF.']);

        $leave = $this->currentUser()->leaveRequests()->findOrFail($this->attachingId);

        if (! $this->attachment instanceof UploadedFile) {
            return;
        }

        $attach->handle($this->currentUser(), $leave, $this->attachment);

        $this->reset(['attachingId', 'attachment']);
        unset($this->requests);
        $this->dispatch('notify', message: 'Attached. Whoever reviews it is told.');
    }

    public function cancel(int $id, CancelLeaveRequest $cancelLeave): void
    {
        // Looked up THROUGH the user, so another employee's id is simply not found.
        $request = $this->currentUser()->leaveRequests()->findOrFail($id);

        $cancelLeave->handle($request, $this->currentUser());

        unset($this->requests);
        $this->dispatch('notify', message: 'Leave request cancelled.');
    }

    /**
     * @return Collection<int, LeaveRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        return $this->currentUser()->leaveRequests()->with(['reviewer', 'documents'])->latest('start_date')->limit(50)->get();
    }

    public function render(): View
    {
        return view('livewire.leave.my-leave', ['groups' => LeaveType::grouped(), 'chosen' => LeaveType::tryFrom($this->type) ?? LeaveType::Vacation]);
    }
}
