<?php

namespace App\Livewire\Admin;

use App\Actions\Leave\EndorseLeaveRequest;
use App\Actions\Leave\ReviewLeaveRequest;
use App\Actions\Leave\SaveLeaveRules;
use App\Enums\Ability;
use App\Enums\LeaveStage;
use App\Enums\RequestStatus;
use App\Livewire\Concerns\GroupsByStaffKind;
use App\Livewire\Concerns\InteractsWithUser;
use App\Models\LeaveRequest;
use App\Support\LeaveRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Guards' leave climbs Satellite Operation → Operation HQ → HR (final);
 * office staff's goes to their head (App\Support\Heads). Each viewer gets the button for their
 * step: Endorse, Review, or a "waiting for…" note.
 *
 * @property-read LengthAwarePaginator<int, LeaveRequest> $requests
 * @property-read LeaveRequest|null $reviewing
 * @property-read LeaveRequest|null $endorsing
 * @property-read int $pendingCount
 */
#[Title('Leave Requests')]
class LeaveRequests extends Component
{
    use GroupsByStaffKind, InteractsWithUser, WithPagination;

    protected function requestModel(): string
    {
        return LeaveRequest::class;
    }

    #[Url(as: 'status', except: 'pending')]
    public string $statusFilter = 'pending';

    public ?int $reviewingId = null;

    public string $reviewNote = '';

    /** The guard's leave being endorsed (Satellite Operation / Operation HQ). */
    public ?int $endorsingId = null;

    public bool $endorseRecommends = true;

    public string $endorseNote = '';

    // Rules: HR edits them here (SaveLeaveRules); others see them.
    public bool $editingRules = false;

    public string $sickCertificateFromDays = '';

    public string $fileAfterDays = '';

    /** HR sets the leave rules; everyone who sees this page sees them. */
    public function canEditRules(): bool
    {
        return $this->currentUser()->can(Ability::ManagePeople->value);
    }

    public function editRules(): void
    {
        abort_unless($this->canEditRules(), 403);
        $rules = LeaveRules::all();
        $this->sickCertificateFromDays = (string) $rules['sick_certificate_from_days'];
        $this->fileAfterDays = (string) $rules['file_after_days'];
        $this->resetErrorBag();
        $this->editingRules = true;
    }

    public function saveRules(SaveLeaveRules $save): void
    {
        $validated = $this->validate([
            'sickCertificateFromDays' => ['required', 'integer', 'min:1', 'max:30'],
            'fileAfterDays' => ['required', 'integer', 'min:0', 'max:30'],
        ], [], ['sickCertificateFromDays' => 'days', 'fileAfterDays' => 'days']);

        $save->handle($this->currentUser(), (int) $validated['sickCertificateFromDays'], (int) $validated['fileAfterDays']);

        $this->editingRules = false;
        $this->dispatch('notify', message: 'Leave rules saved. My Leave uses them from now on.');
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function startEndorse(int $id): void
    {
        $this->authorize(Ability::EndorseGuardLeave->value);

        $request = LeaveRequest::query()->visibleTo($this->currentUser())->findOrFail($id);

        if (EndorseLeaveRequest::endorsesAs($request, $this->currentUser()) === null) {
            $this->addError('list', 'This leave isn\'t waiting for your endorsement.');

            return;
        }

        $this->endorsingId = $request->id;
        $this->endorseRecommends = true;
        $this->endorseNote = '';
        $this->resetErrorBag();
    }

    public function cancelEndorse(): void
    {
        $this->reset(['endorsingId', 'endorseRecommends', 'endorseNote']);
        $this->resetErrorBag();
    }

    public function endorse(EndorseLeaveRequest $endorse): void
    {
        $this->authorize(Ability::EndorseGuardLeave->value);
        $this->validate(['endorseNote' => ['nullable', 'string', 'max:1000']]);

        $endorsement = $endorse->handle(LeaveRequest::query()->visibleTo($this->currentUser())->findOrFail($this->endorsingId), $this->currentUser(), $this->endorseRecommends, $this->endorseNote);

        $this->cancelEndorse();
        unset($this->requests, $this->pendingCount);
        $this->dispatch('notify', message: ($endorsement->recommends ? 'Endorsed' : 'Sent up as not recommended').' to '.($endorsement->stage === LeaveStage::Satellite ? 'Operation HQ' : 'HR').'.');
    }

    #[Computed]
    public function endorsing(): ?LeaveRequest
    {
        return $this->endorsingId !== null ? LeaveRequest::query()->with(['user.employeeProfile', 'endorsements.endorser', 'documents'])->find($this->endorsingId) : null;
    }

    public function startReview(int $id): void
    {
        $this->authorize(Ability::MonitorRequests->value);

        $request = LeaveRequest::query()->visibleTo($this->currentUser())->findOrFail($id);

        if (($reason = ReviewLeaveRequest::blockedReason($request, $this->currentUser())) !== null) {
            $this->addError('list', $reason);

            return;
        }

        $this->reviewingId = $request->id;
        $this->reviewNote = '';
        $this->resetErrorBag();
    }

    public function cancelReview(): void
    {
        $this->reset(['reviewingId', 'reviewNote']);
    }

    public function approve(ReviewLeaveRequest $review): void
    {
        $this->decide($review, RequestStatus::Approved);
    }

    public function reject(ReviewLeaveRequest $review): void
    {
        $this->decide($review, RequestStatus::Rejected);
    }

    private function decide(ReviewLeaveRequest $review, RequestStatus $decision): void
    {
        // Re-checked here: a role removed while this page was open must stop the write.
        // ReviewLeaveRequest then checks this reviewer may decide THIS request.
        $this->authorize(Ability::MonitorRequests->value);

        $this->validate(['reviewNote' => ['nullable', 'string', 'max:1000']]);

        $review->handle(LeaveRequest::query()->visibleTo($this->currentUser())->findOrFail($this->reviewingId), $this->currentUser(), $decision, $this->reviewNote !== '' ? $this->reviewNote : null);

        $this->cancelReview();
        unset($this->requests, $this->pendingCount);
        $this->dispatch('notify', message: 'Leave request '.$decision->value.'.');
    }

    /**
     * @return LengthAwarePaginator<int, LeaveRequest>
     */
    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        return LeaveRequest::query()
            ->with(['user.employeeProfile.client', 'reviewer', 'endorsements.endorser', 'documents'])
            ->visibleTo($this->currentUser())
            ->tap(fn ($query) => $this->inShownGroup($query))
            ->when($this->statusFilter === 'mine', fn ($query) => $query->awaitingActionBy($this->currentUser()))
            ->when(RequestStatus::tryFrom($this->statusFilter), fn ($query, RequestStatus $status) => $query->where('status', $status))
            ->latest('start_date')
            ->paginate(20);
    }

    #[Computed]
    public function reviewing(): ?LeaveRequest
    {
        return $this->reviewingId !== null ? LeaveRequest::query()->with(['user.employeeProfile', 'endorsements.endorser', 'documents'])->find($this->reviewingId) : null;
    }

    #[Computed]
    public function pendingCount(): int
    {
        return LeaveRequest::query()->pending()->visibleTo($this->currentUser())->count();
    }

    public function render(): View
    {
        return view('livewire.admin.leave-requests');
    }
}
